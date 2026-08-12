<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Enums\Asn1Tag;
use LSNepomuceno\LaravelA1PdfSign\Enums\RevocationStatus;
use LSNepomuceno\LaravelA1PdfSign\Support\Pem;
use OpenSSLAsymmetricKey;

/**
 * Reads the OCSP responses and CRLs a document carries, and answers with them.
 *
 * The Document Security Store has been written since 2.0 and, since
 * [0010](../../docs/decisions/0010-validation-consumes-what-signing-writes.md),
 * counted: `$store->ocspResponses` said how many responses were there and
 * nothing said what any of them meant. A document could carry a responder's
 * word that its signer was revoked and still report as valid.
 *
 * **Nothing is believed on sight.** A response or a CRL is evidence only if it
 * verifies against the issuer that signed it, so each one's signature is
 * checked with `openssl_verify()` before its contents are read. Material that
 * does not verify is material that is not there.
 *
 * See docs/decisions/0024-revocation-is-evaluated-not-counted.md.
 *
 * @internal
 */
final readonly class RevocationChecker
{
    /**
     * Signature algorithm OIDs, mapped to the digest `openssl_verify()` wants.
     *
     * The key type comes from the key itself, so only the digest is needed:
     * RSA and ECDSA with the same digest map to the same constant.
     */
    private const array DIGESTS = [
        '1.2.840.113549.1.1.5' => OPENSSL_ALGO_SHA1,
        '1.2.840.113549.1.1.11' => OPENSSL_ALGO_SHA256,
        '1.2.840.113549.1.1.12' => OPENSSL_ALGO_SHA384,
        '1.2.840.113549.1.1.13' => OPENSSL_ALGO_SHA512,
        '1.2.840.10045.4.1' => OPENSSL_ALGO_SHA1,
        '1.2.840.10045.4.3.2' => OPENSSL_ALGO_SHA256,
        '1.2.840.10045.4.3.3' => OPENSSL_ALGO_SHA384,
        '1.2.840.10045.4.3.4' => OPENSSL_ALGO_SHA512,
    ];

    /** id-pkix-ocsp-basic, RFC 6960 §4.2.1. */
    private const string BASIC_RESPONSE = '1.3.6.1.5.5.7.48.1.1';

    public function __construct(
        private Asn1Reader $asn1 = new Asn1Reader(),
    ) {}

    /**
     * What the material says about the certificate whose serial this is.
     *
     * A single verified "revoked" wins over any number of "good"s: a responder
     * saying a certificate is revoked is not something a second opinion undoes.
     *
     * @param  list<string>  $ocspResponses  DER.
     * @param  list<string>  $crls  DER.
     * @param  list<string>  $issuers  Candidate issuer certificates, PEM. The
     *                                 chain, so a delegated responder signed by
     *                                 the issuer is reachable too.
     */
    public function status(string $serialHex, array $ocspResponses, array $crls, array $issuers): RevocationStatus
    {
        $answer = RevocationStatus::Unknown;

        foreach ($ocspResponses as $response) {
            $status = $this->fromOcsp($response, $serialHex, $issuers);

            if ($status === RevocationStatus::Revoked) {
                return $status;
            }

            if ($status === RevocationStatus::Good) {
                $answer = $status;
            }
        }

        foreach ($crls as $crl) {
            $status = $this->fromCrl($crl, $serialHex, $issuers);

            if ($status === RevocationStatus::Revoked) {
                return $status;
            }

            if ($status === RevocationStatus::Good) {
                $answer = $status;
            }
        }

        return $answer;
    }

    /**
     * @param  list<string>  $issuers
     */
    private function fromOcsp(string $der, string $serialHex, array $issuers): RevocationStatus
    {
        $root = $this->asn1->at($der);

        if ($root === null) {
            return RevocationStatus::Unknown;
        }

        $fields = $this->asn1->childrenOf($der, $root);

        // responseStatus, RFC 6960 §4.2.1: anything but 0 carries no answer at
        // all, only the reason the responder declined to give one.
        if (count($fields) < 2 || $fields[0]->content($der) !== "\x00") {
            return RevocationStatus::Unknown;
        }

        $bytes = $this->asn1->path($der, $fields[1], [0]);

        if ($bytes === null) {
            return RevocationStatus::Unknown;
        }

        $parts = $this->asn1->childrenOf($der, $bytes);

        if (count($parts) < 2 || $this->asn1->oid($der, $parts[0]) !== self::BASIC_RESPONSE) {
            return RevocationStatus::Unknown;
        }

        // The BasicOCSPResponse is wrapped in an OCTET STRING, so it is read as
        // a document of its own from here on.
        return $this->fromBasicResponse($parts[1]->content($der), $serialHex, $issuers);
    }

    /**
     * @param  list<string>  $issuers
     */
    private function fromBasicResponse(string $der, string $serialHex, array $issuers): RevocationStatus
    {
        $root = $this->asn1->at($der);

        if ($root === null) {
            return RevocationStatus::Unknown;
        }

        $fields = $this->asn1->childrenOf($der, $root);

        if (count($fields) < 3) {
            return RevocationStatus::Unknown;
        }

        // A responder may be the issuer itself or a certificate the issuer
        // delegated to, which rides along in the response. The delegate has to
        // have been issued by one of them: taking the embedded certificate on
        // sight would let a response vouch for itself, and every forged one
        // does (RFC 6960 §4.2.2.2).
        $keys = array_merge($issuers, $this->delegates($der, $fields[3] ?? null, $issuers));

        if (! $this->verifies($der, $fields[0], $fields[1], $fields[2], $keys)) {
            return RevocationStatus::Unknown;
        }

        $responses = $this->listOf($der, $fields[0], fn(string $d, Asn1Node $n): bool => $this->isSingleResponse($d, $n));

        if ($responses === null) {
            return RevocationStatus::Unknown;
        }

        foreach ($this->asn1->childrenOf($der, $responses) as $single) {
            $parts = $this->asn1->childrenOf($der, $single);

            if (count($parts) < 2 || $this->serialOf($der, $parts[0]) !== $serialHex) {
                continue;
            }

            // certStatus, RFC 6960 §4.2.1: [0] good, [1] revoked, [2] unknown.
            return match ($parts[1]->tag) {
                0x80 => RevocationStatus::Good,
                0xA1, 0x81 => RevocationStatus::Revoked,
                default => RevocationStatus::Unknown,
            };
        }

        return RevocationStatus::Unknown;
    }

    /**
     * @param  list<string>  $issuers
     */
    private function fromCrl(string $der, string $serialHex, array $issuers): RevocationStatus
    {
        $root = $this->asn1->at($der);

        if ($root === null) {
            return RevocationStatus::Unknown;
        }

        $fields = $this->asn1->childrenOf($der, $root);

        if (count($fields) < 3 || ! $this->verifies($der, $fields[0], $fields[1], $fields[2], $issuers)) {
            return RevocationStatus::Unknown;
        }

        $revoked = $this->listOf($der, $fields[0], fn(string $d, Asn1Node $n): bool => $this->isRevokedEntry($d, $n));

        // A CRL with no revoked list is a CRL saying nothing is revoked, which
        // is a real answer rather than an absent one.
        if ($revoked === null) {
            return RevocationStatus::Good;
        }

        foreach ($this->asn1->childrenOf($der, $revoked) as $entry) {
            $parts = $this->asn1->childrenOf($der, $entry);

            if ($parts !== [] && $this->asn1->integerAsHex($der, $parts[0]) === $serialHex) {
                return RevocationStatus::Revoked;
            }
        }

        return RevocationStatus::Good;
    }

    /**
     * Whether the signature over $signed checks out against any of these keys.
     *
     * @param  list<string>  $certificates  PEM.
     */
    private function verifies(string $der, Asn1Node $signed, Asn1Node $algorithm, Asn1Node $signature, array $certificates): bool
    {
        $digest = $this->digestOf($der, $algorithm);

        if ($digest === null || ! $signature->is(Asn1Tag::BitString)) {
            return false;
        }

        // A BIT STRING's first content byte counts the unused trailing bits,
        // which is zero for a signature and is not part of it.
        $bits = substr($signature->content($der), 1);
        $data = $signed->raw($der);

        foreach ($certificates as $certificate) {
            $key = @openssl_pkey_get_public($certificate);

            if ($key instanceof OpenSSLAsymmetricKey && openssl_verify($data, $bits, $key, $digest) === 1) {
                return true;
            }
        }

        return false;
    }

    private function digestOf(string $der, Asn1Node $algorithm): ?int
    {
        $oid = $this->asn1->oid($der, $this->asn1->path($der, $algorithm, [0]));

        return $oid === null ? null : (self::DIGESTS[$oid] ?? null);
    }

    /**
     * The child that is a SEQUENCE OF whatever $matches recognises.
     *
     * Both structures put their list among optional fields, so it is found by
     * what its entries look like rather than by counting positions: a CRL with
     * no version and no nextUpdate would shift every index.
     *
     * @param  callable(string, Asn1Node): bool  $matches
     */
    private function listOf(string $der, Asn1Node $parent, callable $matches): ?Asn1Node
    {
        foreach ($this->asn1->childrenOf($der, $parent) as $child) {
            if (! $child->is(Asn1Tag::Sequence)) {
                continue;
            }

            $entries = $this->asn1->childrenOf($der, $child);

            if ($entries !== [] && $matches($der, $entries[0])) {
                return $child;
            }
        }

        return null;
    }

    /**
     * SEQUENCE { userCertificate INTEGER, revocationDate Time, … }.
     */
    private function isRevokedEntry(string $der, Asn1Node $entry): bool
    {
        if (! $entry->is(Asn1Tag::Sequence)) {
            return false;
        }

        $parts = $this->asn1->childrenOf($der, $entry);

        return count($parts) >= 2
            && $parts[0]->is(Asn1Tag::Integer)
            && ($parts[1]->is(Asn1Tag::UtcTime) || $parts[1]->is(Asn1Tag::GeneralizedTime));
    }

    /**
     * SEQUENCE { certID SEQUENCE {…, serialNumber INTEGER}, certStatus, … }.
     */
    private function isSingleResponse(string $der, Asn1Node $entry): bool
    {
        if (! $entry->is(Asn1Tag::Sequence)) {
            return false;
        }

        $parts = $this->asn1->childrenOf($der, $entry);

        return $parts !== [] && $this->serialOf($der, $parts[0]) !== null;
    }

    /**
     * The serial a CertID names, RFC 6960 §4.1.1: the last of its four fields.
     */
    private function serialOf(string $der, Asn1Node $certId): ?string
    {
        if (! $certId->is(Asn1Tag::Sequence)) {
            return null;
        }

        $parts = $this->asn1->childrenOf($der, $certId);

        return count($parts) === 4 ? $this->asn1->integerAsHex($der, $parts[3]) : null;
    }

    /**
     * The responders a BasicOCSPResponse carries that one of $issuers issued.
     *
     * Verified rather than read. A response carries whatever certificate its
     * author chose to put in it, so accepting one because it is there is
     * accepting the response's own word for who signed it.
     *
     * @param  list<string>  $issuers  PEM.
     * @return list<string>  PEM.
     */
    private function delegates(string $der, ?Asn1Node $certs, array $issuers): array
    {
        if ($certs === null || ! $certs->is(Asn1Tag::Context0)) {
            return [];
        }

        $found = [];

        foreach ($this->asn1->children($der, $certs->contentOffset()) as $certificate) {
            $pem = Pem::fromDer($certificate->raw($der));

            if (@openssl_x509_read($pem) === false) {
                continue;
            }

            foreach ($issuers as $issuer) {
                if (@openssl_x509_verify($pem, $issuer) === 1) {
                    $found[] = $pem;

                    break;
                }
            }
        }

        return $found;
    }
}
