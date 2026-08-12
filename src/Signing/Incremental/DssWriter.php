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
        $material = $this->collect($certificate);

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
     * @return array{certs: list<string>, ocsp: list<string>, crls: list<string>}|null
     */
    private function collect(Certificate $certificate): ?array
    {
        $chain = $this->chain($certificate);

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
     * @return list<string>
     */
    private function chain(Certificate $certificate): array
    {
        return Pem::certificates($certificate->original);
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
