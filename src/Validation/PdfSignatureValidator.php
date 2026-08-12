<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureDetails;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Enums\RevocationStatus;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\CertificationReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * Reports on every signature in a document.
 */
final readonly class PdfSignatureValidator implements SignatureValidator
{
    public function __construct(
        private PdfSignatureExtractor $extractor,
        private Pkcs7Reader $reader,
        private SignatureVerifier $verifier,
        private SecurityStoreReader $store = new SecurityStoreReader(),
        private ChainBuilder $chains = new ChainBuilder(),
        private CertificationReader $certifications = new CertificationReader(new DocumentReader()),
        // Optional so the constructor's arity does not move, and because a
        // validator built by hand without it degrades to the same answer as
        // one called without a store: trust unknown, rather than untrusted
        // (docs/decisions/0016-trust-is-the-applications-policy.md).
        private ?TrustVerifier $trust = null,
        // Appended rather than slotted in beside the other readers, so a caller
        // passing $trust positionally keeps meaning what they meant.
        private TimestampTokenReader $timestamps = new TimestampTokenReader(),
        private RevocationReader $revocations = new RevocationReader(new DocumentReader()),
        private RevocationChecker $revocationChecker = new RevocationChecker(),
    ) {}

    /**
     * @throws FileNotFoundException
     * @throws InvalidPdfFileException
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validateFile(string $pdfPath, ?TrustStore $trust = null): SignatureReport
    {
        if (! str_ends_with(strtolower($pdfPath), '.pdf')) {
            throw InvalidPdfFileException::extension($pdfPath);
        }

        if (! File::exists($pdfPath)) {
            throw new FileNotFoundException($pdfPath);
        }

        return $this->validate(Files::read($pdfPath), $pdfPath, $trust);
    }

    /**
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validate(string $pdfContents, string $label = 'the document', ?TrustStore $trust = null): SignatureReport
    {
        $extracted = $this->extractor->extract($pdfContents);

        if ($extracted === []) {
            throw new HasNoSignatureOrInvalidPkcs7Exception($label);
        }

        $size = strlen($pdfContents);
        $signatures = [];

        // Read before the loop because two of them are facts about the
        // document, not about one signature: which level a signature reaches
        // depends on what the file carries around it.
        $store = $this->store->read($pdfContents);
        $archived = array_filter($extracted, static fn(array $entry): bool => $entry['isTimestamp']) !== [];
        $material = $this->material($pdfContents);

        foreach ($extracted as $signature) {
            [$open, $close, $trailing] = $signature['byteRange'];

            $ordered = $this->chains->build($this->reader->certificates($signature['cms']));
            $chain = $this->reader->signersFromPem($ordered);

            // A DocTimeStamp carries no signature value to stamp, so it is
            // never asked; it is itself the token, and is verified as one below.
            $stamp = $signature['isTimestamp']
                ? ['verified' => null, 'at' => null]
                : $this->stamp($signature['cms']);

            $signatures[] = new SignatureDetails(
                // A timestamp is verified against its own imprint rather than
                // as a detached signature, which is why the two paths differ
                // (docs/decisions/0010-validation-consumes-what-signing-writes.md).
                verified: $signature['isTimestamp']
                    ? $this->verifier->verifyTimestamp(
                        $signature['cms'],
                        $this->extractor->coveredBytes($pdfContents, $open, $close, $trailing),
                    )
                    : $this->verifier->verify(
                        $signature['cms'],
                        $this->extractor->coveredBytes($pdfContents, $open, $close, $trailing),
                    ),
                signers: $this->reader->signers($signature['cms']),
                coverageEnd: $signature['coverageEnd'],
                // Only the last signature reaches the end of the file; the
                // earlier ones stop at the revision they were written into,
                // which is expected rather than a defect.
                coversWholeDocument: $signature['coverageEnd'] === $size,
                isTimestamp: $signature['isTimestamp'],
                signedAt: $signature['signedAt'],
                rawContents: $signature['cms'],
                chain: $chain,
                chainReachesRoot: $chain !== [] && $this->chains->reachesRoot($ordered),
                // Null, not false, when nobody was asked
                // (docs/decisions/0016-trust-is-the-applications-policy.md).
                isTrusted: $trust === null || $this->trust === null
                    ? null
                    : $this->trust->trusts($trust, $ordered),
                timestampVerified: $stamp['verified'],
                stampedAt: $stamp['at'],
                subFilter: $signature['subFilter'],
                profile: SignatureProfile::classify(
                    $signature['subFilter'],
                    $stamp['verified'] === true,
                    $store !== null && ! $store->isEmpty(),
                    $archived,
                ),
                revocation: $this->revocation($chain, $ordered, $material),
            );
        }

        return new SignatureReport(
            $signatures,
            $store,
            // A document with no readable cross-reference chain still has
            // signatures worth reporting, so a certification that cannot be
            // located is absent rather than fatal.
            $this->certification($pdfContents),
        );
    }

    /**
     * The verdict on this signature's own RFC 3161 token.
     *
     * The package has embedded one at `pades-b-t` and above since 2.0 and never
     * looked at it, so a B-T document reported valid without anyone checking the
     * single thing that profile adds over B-B. Only the DocTimeStamp of B-LTA
     * was ever verified, which is the same asymmetry one level down
     * (docs/decisions/0019-validation-reads-what-it-writes.md).
     *
     * The token stamps the SignerInfo's signature value, not the document, so a
     * verifier handed the document's bytes would fail on every correctly built
     * file.
     *
     * @return array{verified: ?bool, at: ?int} Both null when there is no token:
     *                                          absence is not failure, and it is
     *                                          the ordinary case at B-B.
     */
    private function stamp(string $cms): array
    {
        $token = $this->timestamps->read($cms);

        if ($token === null) {
            return ['verified' => null, 'at' => null];
        }

        $info = $this->verifier->verifiedTimestampInfo($token['token'], $token['stamped']);

        return $info === null
            ? ['verified' => false, 'at' => null]
            : ['verified' => true, 'at' => $this->timestamps->stampedAt($info)];
    }

    /**
     * What the document's own revocation material says about this signer.
     *
     * The store has been written since 2.0 and counted since 2.2, and nothing
     * read it, so a document could carry a responder's word that its signer was
     * revoked and still report as valid
     * (docs/decisions/0024-revocation-is-evaluated-not-counted.md).
     *
     * The issuers offered are the rest of the chain the signature embeds, so a
     * response signed by the issuer, or by a responder the issuer delegated to,
     * is reachable and nothing else is.
     *
     * @param  list<\LSNepomuceno\LaravelA1PdfSign\Data\Signer>  $chain
     * @param  list<string>  $ordered  The same chain as PEM, leaf first.
     * @param  array{ocsp: list<string>, crls: list<string>}  $material
     */
    private function revocation(array $chain, array $ordered, array $material): RevocationStatus
    {
        $serial = $chain[0]->serialNumber ?? null;

        if ($serial === null || ($material['ocsp'] === [] && $material['crls'] === [])) {
            return RevocationStatus::Unknown;
        }

        return $this->revocationChecker->status(
            $serial,
            $material['ocsp'],
            $material['crls'],
            array_slice($ordered, 1),
        );
    }

    /**
     * @return array{ocsp: list<string>, crls: list<string>}
     */
    private function material(string $pdfContents): array
    {
        try {
            return $this->revocations->material($pdfContents);
        } catch (InvalidPdfFileException) {
            // A document whose cross-reference chain cannot be read still has
            // signatures worth reporting, the same way a certification that
            // cannot be located is absent rather than fatal.
            return ['ocsp' => [], 'crls' => []];
        }
    }

    private function certification(string $pdfContents): ?CertificationLevel
    {
        try {
            return $this->certifications->level($pdfContents);
        } catch (InvalidPdfFileException) {
            return null;
        }
    }
}
