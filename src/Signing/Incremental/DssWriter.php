<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use Com\Tecnick\Pdf\Sign\Output\Dss;
use Com\Tecnick\Pdf\Sign\Signer;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Support\Pem;
use Throwable;

/**
 * Appends the Document Security Store that makes a signature PAdES B-LT.
 *
 * A signature stops verifying once its certificate expires or is revoked,
 * unless the document itself carries the revocation evidence gathered while
 * the certificate was still good. The DSS is that evidence: the chain, plus
 * OCSP responses and CRLs, embedded in a revision appended *after* signing so
 * the signature it vouches for stays intact.
 *
 * @internal
 */
final readonly class DssWriter
{
    public function __construct(
        private DocumentReader $reader,
        private RevisionWriter $writer,
        private ByteRangeCalculator $byteRange,
        private SignatureTransport $transport,
        private Signer $signer = new Signer(),
        private Dss $dss = new Dss(),
    ) {}

    /**
     * @throws InvalidPdfFileException
     */
    public function append(string $pdf, Certificate $certificate): string
    {
        return $this->write($pdf, $this->collect(Pem::certificates($certificate->original)));
    }

    /**
     * A store built from the certificates the document already carries, with
     * the revocation material fetched now rather than when it was signed.
     *
     * This is what an archive timestamp needs before it is written: the
     * evidence for everything up to this point has to be inside the file while
     * it is still verifiable, and then the timestamp covers it
     * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
     *
     * @param  list<list<string>>  $chains  One chain per signature, leaf first.
     *                                      Kept apart rather than pooled: the
     *                                      collector pairs each certificate with
     *                                      the next one as its issuer, so a
     *                                      mixed pile would build OCSP requests
     *                                      against the wrong issuer.
     *
     * @throws InvalidPdfFileException
     */
    public function refresh(string $pdf, array $chains): string
    {
        $material = ['certs' => [], 'ocsp' => [], 'crls' => []];

        foreach ($chains as $chain) {
            $collected = $this->collect($chain);

            if ($collected === null) {
                continue;
            }

            foreach ($material as $kind => $items) {
                $material[$kind] = [...$items, ...$collected[$kind]];
            }
        }

        foreach ($material as $kind => $items) {
            $material[$kind] = array_values(array_unique($items));
        }

        return $this->write(
            $pdf,
            $material['certs'] === [] && $material['ocsp'] === [] && $material['crls'] === [] ? null : $material,
        );
    }

    /**
     * @param  array{certs: list<string>, ocsp: list<string>, crls: list<string>}|null  $material
     *
     * @throws InvalidPdfFileException
     */
    private function write(string $pdf, ?array $material): string
    {
        if ($material === null) {
            return $pdf;
        }

        $document = $this->reader->read($pdf);

        // The store is keyed by the signature it vouches for, so the emitter
        // needs the /Contents bytes of the signature just written.
        $objectNumber = $document->size;
        $emitted = $this->dss->emit($material, $this->signatureContents($pdf), $objectNumber);

        $objects = $emitted['objects'];
        $objects[$document->root] = $this->writer->catalogWithDss($pdf, $document, $emitted['object_id']);

        return $this->writer->appendObjects($pdf, $document, $objects);
    }

    /**
     * Gathers the revocation material, or null when there is none to embed.
     *
     * A self-signed certificate has neither an OCSP responder nor a CRL
     * distribution point, and an unreachable responder must not fail the
     * signature: in both cases the document simply stays at B-T.
     *
     * @param  list<string>  $chain  Leaf first.
     * @return array{certs: list<string>, ocsp: list<string>, crls: list<string>}|null
     */
    private function collect(array $chain): ?array
    {
        if ($chain === []) {
            return null;
        }

        try {
            $material = $this->signer->collectValidationMaterial(
                $chain,
                $this->transport->ocsp(),
                $this->transport->crl(),
            );
        } catch (Throwable) {
            return null;
        }

        return $material['certs'] === [] && $material['ocsp'] === [] && $material['crls'] === []
            ? null
            : $material;
    }

    /**
     * The hex-decoded /Contents of the signature this store covers.
     *
     * @throws InvalidPdfFileException
     */
    private function signatureContents(string $pdf): string
    {
        [$open, $close] = $this->byteRange->readLast($pdf);

        $hex = substr($pdf, $open + 1, $close - $open - 2);

        return (string) hex2bin(rtrim($hex, '0') . (strlen(rtrim($hex, '0')) % 2 === 1 ? '0' : ''));
    }
}
