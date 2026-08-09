<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

/**
 * Pulls every signature out of a document.
 *
 * The 1.x code read `$result[2][0]`, the first match only, so a document
 * with more than one signature reported on the first and ignored the rest.
 * Now that the package emits multi-signature documents, that would mean it
 * could not describe its own output.
 */
final class PdfSignatureExtractor
{
    public function __construct(private readonly DerReader $der = new DerReader()) {}

    /**
     * @return list<array{byteRange: array{0:int,1:int,2:int}, cms: string, coverageEnd: int, isTimestamp: bool, signedAt: ?int}>
     */
    public function extract(string $pdf): array
    {
        // ISO 32000-1 allows any whitespace between the four numbers, and a
        // signer must pad them to a fixed width to patch the values in place.
        if (preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $signatures = [];

        foreach ($matches as $match) {
            [$open, $close, $trailing] = [(int) $match[1][0], (int) $match[2][0], (int) $match[3][0]];

            $cms = $this->contents($pdf, $open, $close);

            if ($cms === null) {
                continue;
            }

            $signatures[] = [
                'byteRange' => [$open, $close, $trailing],
                'cms' => $cms,
                'coverageEnd' => $close + $trailing,
                'isTimestamp' => $this->isDocumentTimestamp($pdf, $match[0][1]),
                'signedAt' => $this->claimedTime($pdf, $match[0][1]),
            ];
        }

        return $signatures;
    }

    /**
     * Whether the entry is an archive timestamp rather than a signature.
     *
     * A /DocTimeStamp carries an RFC 3161 token, whose SignedData signs the
     * TSTInfo holding the document's hash, not the document itself. Verifying
     * it the way a signature is verified always fails, so it has to be told
     * apart before anything tries.
     */
    private function isDocumentTimestamp(string $pdf, int $byteRangeOffset): bool
    {
        // The /Type and /SubFilter sit in the same dictionary, just ahead of
        // the /ByteRange this entry was found at.
        $dictionary = substr($pdf, max(0, $byteRangeOffset - 200), 200);

        return str_contains($dictionary, '/DocTimeStamp') || str_contains($dictionary, 'ETSI.RFC3161');
    }

    /**
     * The time the signature dictionary claims, as a unix timestamp.
     *
     * Read from /M in the same dictionary rather than from a CMS signed
     * attribute. Measured on a freshly signed document, the PKCS#9
     * signing-time OID is absent from the CMS entirely: tc-lib-pdf-sign does
     * not emit it, and /M is both what this package writes and what poppler
     * reports as the signing time.
     *
     * Searched forward, unlike the /SubFilter above. /M is written after
     * /Contents, whose placeholder is 16 KB of hex, so it sits far past the
     * /ByteRange this entry was found at rather than just ahead of it.
     *
     * /M is inside the range the signature covers, so altering it breaks the
     * signature. It remains the signer's own clock, which is why the report
     * calls it claimed rather than proven.
     */
    private function claimedTime(string $pdf, int $byteRangeOffset): ?int
    {
        // Wide enough to clear the /Contents placeholder and the metadata after
        // it, and bounded so a document with several signatures cannot answer
        // for one entry with the dictionary of a later one.
        $window = substr($pdf, $byteRangeOffset, 32_768);

        if (preg_match('/\/M\s*\(D:(\d{14})/', $window, $found) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('YmdHis', $found[1], new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->getTimestamp();
    }

    /**
     * The bytes a signature covers: everything except its own /Contents.
     */
    public function coveredBytes(string $pdf, int $open, int $close, int $trailing): string
    {
        return substr($pdf, 0, $open) . substr($pdf, $close, $trailing);
    }

    /**
     * The hex-decoded /Contents, trimmed to the length its ASN.1 header
     * declares.
     *
     * The placeholder is zero-padded on the right, so trimming with rtrim()
     * would cut legitimate 0x00 bytes off the end of the DER itself.
     */
    private function contents(string $pdf, int $open, int $close): ?string
    {
        $hex = substr($pdf, $open + 1, $close - $open - 2);

        if ($hex === '' || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            return null;
        }

        $binary = hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);

        if ($binary === false || strlen($binary) < 2) {
            return null;
        }

        $der = $this->der->truncate($binary);

        return $der === '' ? null : $der;
    }

}
