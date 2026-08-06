<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

/**
 * Pulls every signature out of a document.
 *
 * The 1.x code read `$result[2][0]` — the first match only — so a document
 * with more than one signature reported on the first and ignored the rest.
 * Now that the package emits multi-signature documents, that would mean it
 * could not describe its own output.
 */
final class PdfSignatureExtractor
{
    /**
     * @return list<array{byteRange: array{0:int,1:int,2:int}, cms: string, coverageEnd: int}>
     */
    public function extract(string $pdf): array
    {
        // ISO 32000-1 allows any whitespace between the four numbers, and a
        // signer must pad them to a fixed width to patch the values in place.
        if (! preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $signatures = [];

        foreach ($matches as $match) {
            [$open, $close, $trailing] = [(int) $match[1], (int) $match[2], (int) $match[3]];

            $cms = $this->contents($pdf, $open, $close);

            if ($cms === null) {
                continue;
            }

            $signatures[] = [
                'byteRange' => [$open, $close, $trailing],
                'cms' => $cms,
                'coverageEnd' => $close + $trailing,
            ];
        }

        return $signatures;
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

        $der = $this->truncateToDeclaredLength($binary);

        return $der === '' ? null : $der;
    }

    private function truncateToDeclaredLength(string $binary): string
    {
        $lengthByte = ord($binary[1]);

        if ($lengthByte < 0x80) {
            return substr($binary, 0, 2 + $lengthByte);
        }

        $count = $lengthByte & 0x7F;

        if ($count === 0 || strlen($binary) < 2 + $count) {
            return '';
        }

        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($binary[2 + $i]);
        }

        return substr($binary, 0, 2 + $count + $length);
    }
}
