<?php

declare(strict_types=1);

/**
 * PoC 0b: incremental revision writer for PDF signatures.
 *
 * Clean-room implementation based on ISO 32000-1:
 *   §7.5.6  Incremental Updates
 *   §12.8   Digital Signatures
 *
 * No line is derived from ddn/sapp (LGPL). See docs/spec/invariants.md.
 *
 * Spike scope: classic cross-reference tables only. Cross-reference streams
 * (PDF 1.5+) are detected and explicitly rejected: support lands in the
 * production implementation.
 */
final class IncrementalSigner
{
    /** Fixed size of the /Contents placeholder, in hex characters. */
    private const CONTENTS_HEX_LEN = 16384;

    /** Fixed-width /ByteRange field, so values can be patched without shifting offsets. */
    private const BYTERANGE_FIELD = '**********';

    /**
     * Appends a signed revision to the PDF, leaving the original bytes untouched.
     *
     * @param array{Name?:string,Reason?:string,Location?:string,ContactInfo?:string} $info
     */
    public function sign(
        string $pdf,
        string $certPem,
        string $keyPem,
        array $info = [],
        string $fieldName = 'Signature',
    ): string {
        $doc = $this->readDocument($pdf);

        $nextObj   = $doc['size'];
        $sigNum    = $nextObj;
        $widgetNum = $nextObj + 1;

        $catalogNum = $doc['root'];
        $pageNum    = $this->findFirstPage($pdf, $doc);

        $catalog = $this->rewriteCatalog($this->rawObject($pdf, $doc, $catalogNum), $widgetNum);
        $page    = $this->rewritePage($this->rawObject($pdf, $doc, $pageNum), $widgetNum);

        // --- assemble the revision with placeholders ------------------------
        $base = strlen($pdf);
        $body = "\n";

        $offsets = [];

        $offsets[$sigNum] = $base + strlen($body);
        $body .= $this->signatureObject($sigNum, $info);

        $offsets[$widgetNum] = $base + strlen($body);
        $body .= $this->widgetObject($widgetNum, $sigNum, $pageNum, $fieldName . $sigNum);

        $offsets[$catalogNum] = $base + strlen($body);
        $body .= "{$catalogNum} 0 obj\n{$catalog}\nendobj\n";

        $offsets[$pageNum] = $base + strlen($body);
        $body .= "{$pageNum} 0 obj\n{$page}\nendobj\n";

        $xrefOffset = $base + strlen($body);
        $newSize    = max(array_keys($offsets)) + 1;

        $body .= $this->xrefSection($offsets);
        $body .= $this->trailer($newSize, $catalogNum, $doc['startxref'], $doc['infoRef']);
        $body .= "startxref\n{$xrefOffset}\n%%EOF\n";

        $full = $pdf . $body;

        // --- byte range and signature ---------------------------------------
        $full = $this->applyByteRange($full);

        return $this->applySignature($full, $certPem, $keyPem);
    }

    // =====================================================================
    // Reading
    // =====================================================================

    /**
     * Walks the /Prev chain and builds the effective object map.
     * Newer revisions override older ones.
     *
     * @return array{xref:array<int,int>,size:int,root:int,infoRef:?string,startxref:int}
     */
    private function readDocument(string $pdf): array
    {
        if (!preg_match_all('/startxref\s+(\d+)\s*%%EOF/', $pdf, $m)) {
            throw new RuntimeException('startxref not found: invalid or truncated PDF.');
        }

        $latest = (int) end($m[1]);

        $xref    = [];
        $size    = 0;
        $root    = 0;
        $infoRef = null;
        $offset  = $latest;
        $seen    = [];

        $chain = [];
        while ($offset > 0 && !isset($seen[$offset])) {
            $seen[$offset] = true;
            $section = $this->readXrefSection($pdf, $offset);
            $chain[] = $section;
            $offset  = $section['prev'];
        }

        // $chain runs newest to oldest; walk it reversed so the most recent
        // entries overwrite the older ones.
        foreach (array_reverse($chain) as $section) {
            $xref = array_replace($xref, $section['xref']);
            $size = max($size, $section['size']);

            if ($section['root'] > 0) {
                $root = $section['root'];
            }
            if ($section['infoRef'] !== null) {
                $infoRef = $section['infoRef'];
            }
        }

        if ($root === 0) {
            throw new RuntimeException('/Root not found in trailer.');
        }

        return [
            'xref'      => $xref,
            'size'      => $size,
            'root'      => $root,
            'infoRef'   => $infoRef,
            'startxref' => $latest,
        ];
    }

    /**
     * @return array{xref:array<int,int>,size:int,root:int,infoRef:?string,prev:int}
     */
    private function readXrefSection(string $pdf, int $offset): array
    {
        $head = ltrim(substr($pdf, $offset, 32));

        if (!str_starts_with($head, 'xref')) {
            throw new RuntimeException(
                "Cross-reference stream (PDF 1.5+) found at @{$offset}. "
                . 'This spike only supports classic cross-reference tables.',
            );
        }

        $pos = strpos($pdf, 'xref', $offset);
        if ($pos === false) {
            throw new RuntimeException("Unreadable cross-reference section at @{$offset}.");
        }
        $pos += 4;

        $xref = [];

        // Subsections: "<start> <count>" followed by <count> 20-byte entries.
        while (true) {
            if (!preg_match('/\G\s*(\d+)\s+(\d+)\s*(?:\r\n|\r|\n)/', $pdf, $m, 0, $pos)) {
                break;
            }
            $start = (int) $m[1];
            $count = (int) $m[2];
            $pos  += strlen($m[0]);

            for ($i = 0; $i < $count; $i++) {
                $entry = substr($pdf, $pos + ($i * 20), 20);
                if (preg_match('/^(\d{10})\s(\d{5})\s([nf])/', $entry, $e) && $e[3] === 'n') {
                    $xref[$start + $i] = (int) $e[1];
                }
            }
            $pos += $count * 20;
        }

        $trailerPos = strpos($pdf, 'trailer', $pos);
        if ($trailerPos === false) {
            throw new RuntimeException('trailer not found after the cross-reference table.');
        }
        $trailer = substr($pdf, $trailerPos, 2048);

        preg_match('/\/Size\s+(\d+)/', $trailer, $ms);
        preg_match('/\/Root\s+(\d+)\s+\d+\s+R/', $trailer, $mr);
        preg_match('/\/Prev\s+(\d+)/', $trailer, $mp);
        preg_match('/\/Info\s+(\d+\s+\d+\s+R)/', $trailer, $mi);

        return [
            'xref'    => $xref,
            'size'    => isset($ms[1]) ? (int) $ms[1] : 0,
            'root'    => isset($mr[1]) ? (int) $mr[1] : 0,
            'infoRef' => $mi[1] ?? null,
            'prev'    => isset($mp[1]) ? (int) $mp[1] : 0,
        ];
    }

    /** Returns the raw dictionary of an object, without "N 0 obj" or "endobj". */
    private function rawObject(string $pdf, array $doc, int $num): string
    {
        $offset = $doc['xref'][$num] ?? null;
        if ($offset === null) {
            throw new RuntimeException("Object {$num} is missing from the cross-reference table.");
        }

        $end = strpos($pdf, 'endobj', $offset);
        if ($end === false) {
            throw new RuntimeException("endobj not found for object {$num}.");
        }

        $raw = substr($pdf, $offset, $end - $offset);
        $raw = preg_replace('/^\s*\d+\s+\d+\s+obj\s*/', '', $raw, 1);

        return rtrim((string) $raw);
    }

    private function findFirstPage(string $pdf, array $doc): int
    {
        foreach ($doc['xref'] as $num => $offset) {
            if ($offset <= 0) {
                continue;
            }
            $chunk = substr($pdf, $offset, 400);
            if (preg_match('/\/Type\s*\/Page(?![s\w])/', $chunk)) {
                return $num;
            }
        }

        throw new RuntimeException('No /Type /Page object found.');
    }

    // =====================================================================
    // Object writing
    // =====================================================================

    private function signatureObject(int $num, array $info): string
    {
        $byteRange = '/ByteRange[0 ' . self::BYTERANGE_FIELD
            . ' ' . self::BYTERANGE_FIELD
            . ' ' . self::BYTERANGE_FIELD . ']';

        $meta = '';
        foreach (['Name', 'Reason', 'Location', 'ContactInfo'] as $key) {
            if (!empty($info[$key])) {
                $meta .= "/{$key} (" . $this->escapeString((string) $info[$key]) . ') ';
            }
        }

        return "{$num} 0 obj\n"
            . '<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/adbe.pkcs7.detached '
            . $byteRange
            . '/Contents <' . str_repeat('0', self::CONTENTS_HEX_LEN) . '> '
            . $meta
            . '/M (' . $this->pdfDate() . ')'
            . ">>\nendobj\n";
    }

    private function widgetObject(int $num, int $sigNum, int $pageNum, string $name): string
    {
        return "{$num} 0 obj\n"
            . '<</Type/Annot/Subtype/Widget/FT/Sig'
            . '/Rect[0 0 0 0]'          // invisible signature; the visual seal comes later
            . "/T ({$name})"
            . "/V {$sigNum} 0 R"
            . "/P {$pageNum} 0 R"
            . '/F 132'                  // Print | NoView-off; 4|128
            . '/Ff 0'
            . ">>\nendobj\n";
    }

    /** Adds the field to /AcroForm, creating the dictionary when absent. */
    private function rewriteCatalog(string $catalog, int $widgetNum): string
    {
        if (preg_match('/\/AcroForm\s*<<(.*?)>>/s', $catalog, $m)) {
            $acro = $m[1];

            if (preg_match('/\/Fields\s*\[(.*?)\]/s', $acro, $f)) {
                $fields  = trim($f[1]);
                $updated = preg_replace(
                    '/\/Fields\s*\[.*?\]/s',
                    '/Fields [' . trim($fields . " {$widgetNum} 0 R") . ']',
                    $acro,
                    1,
                );
            } else {
                $updated = $acro . "/Fields [{$widgetNum} 0 R]";
            }

            if (!str_contains($updated, '/SigFlags')) {
                $updated .= '/SigFlags 3';
            }

            return str_replace($m[0], '/AcroForm <<' . $updated . '>>', $catalog);
        }

        // No /AcroForm yet: inject it before the dictionary's closing >>.
        $acroForm = "/AcroForm <</Fields [{$widgetNum} 0 R]/SigFlags 3>>";

        return $this->injectBeforeLastDictClose($catalog, $acroForm);
    }

    /** Adds the widget to the page's /Annots, creating the array when absent. */
    private function rewritePage(string $page, int $widgetNum): string
    {
        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page, $m)) {
            $annots = trim($m[1]);

            return str_replace(
                $m[0],
                '/Annots [' . trim($annots . " {$widgetNum} 0 R") . ']',
                $page,
            );
        }

        return $this->injectBeforeLastDictClose($page, "/Annots [{$widgetNum} 0 R]");
    }

    private function injectBeforeLastDictClose(string $dict, string $insert): string
    {
        $pos = strrpos($dict, '>>');
        if ($pos === false) {
            throw new RuntimeException('Malformed dictionary: closing >> not found.');
        }

        return substr($dict, 0, $pos) . $insert . substr($dict, $pos);
    }

    // =====================================================================
    // Cross-reference table and trailer
    // =====================================================================

    /** @param array<int,int> $offsets */
    private function xrefSection(array $offsets): string
    {
        ksort($offsets);

        // Group consecutive object numbers into subsections.
        $groups  = [];
        $current = [];
        $prev    = null;

        foreach ($offsets as $num => $off) {
            if ($prev !== null && $num !== $prev + 1) {
                $groups[] = $current;
                $current  = [];
            }
            $current[$num] = $off;
            $prev          = $num;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        $out = "xref\n";
        foreach ($groups as $group) {
            $start = array_key_first($group);
            $out  .= $start . ' ' . count($group) . "\n";
            foreach ($group as $off) {
                // Each entry is exactly 20 bytes.
                $out .= sprintf("%010d %05d n \n", $off, 0);
            }
        }

        return $out;
    }

    private function trailer(int $size, int $root, int $prev, ?string $infoRef): string
    {
        $info = $infoRef !== null ? "/Info {$infoRef}" : '';

        return "trailer\n<</Size {$size}/Root {$root} 0 R{$info}/Prev {$prev}>>\n";
    }

    // =====================================================================
    // Byte range and PKCS#7
    // =====================================================================

    /** Computes /ByteRange and writes it back at exactly the placeholder width. */
    private function applyByteRange(string $full): string
    {
        $contentsPos = strrpos($full, '/Contents <');
        if ($contentsPos === false) {
            throw new RuntimeException('/Contents placeholder not found.');
        }

        $open  = $contentsPos + strlen('/Contents ');       // offset of '<'
        $close = $open + 1 + self::CONTENTS_HEX_LEN + 1;    // offset just past '>'

        $range = [0, $open, $close, strlen($full) - $close];

        $placeholder = '/ByteRange[0 ' . self::BYTERANGE_FIELD
            . ' ' . self::BYTERANGE_FIELD
            . ' ' . self::BYTERANGE_FIELD . ']';

        $replacement = sprintf(
            '/ByteRange[0 %s %s %s]',
            str_pad((string) $range[1], strlen(self::BYTERANGE_FIELD)),
            str_pad((string) $range[2], strlen(self::BYTERANGE_FIELD)),
            str_pad((string) $range[3], strlen(self::BYTERANGE_FIELD)),
        );

        if (strlen($replacement) !== strlen($placeholder)) {
            throw new RuntimeException('ByteRange would change length, so offsets would be invalidated.');
        }

        $pos = strrpos($full, $placeholder);
        if ($pos === false) {
            throw new RuntimeException('/ByteRange placeholder not found.');
        }

        return substr_replace($full, $replacement, $pos, strlen($placeholder));
    }

    /** Signs both byte-range spans and injects the detached CMS into the placeholder. */
    private function applySignature(string $full, string $certPem, string $keyPem): string
    {
        // An already-signed document holds several /ByteRange entries. Ours is
        // always the LAST one, the freshly appended revision. Taking the first
        // would overwrite the /Contents of a previous signature.
        if (!preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $full, $all, PREG_SET_ORDER)) {
            throw new RuntimeException('ByteRange could not be read back.');
        }

        $m = end($all);

        [$a, $b, $c] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        $signable = substr($full, 0, $a) . substr($full, $b, $c);

        $der = $this->pkcs7Detached($signable, $certPem, $keyPem);
        $hex = bin2hex($der);

        if (strlen($hex) > self::CONTENTS_HEX_LEN) {
            throw new RuntimeException(sprintf(
                'CMS of %d bytes does not fit the %d-byte placeholder. Increase CONTENTS_HEX_LEN.',
                strlen($der),
                intdiv(self::CONTENTS_HEX_LEN, 2),
            ));
        }

        $hex = str_pad($hex, self::CONTENTS_HEX_LEN, '0');

        // Replace only the hex payload between '<' and '>', so no offset moves.
        return substr_replace($full, $hex, $a + 1, self::CONTENTS_HEX_LEN);
    }

    private function pkcs7Detached(string $data, string $certPem, string $keyPem): string
    {
        $in  = tempnam(sys_get_temp_dir(), 'a1in');
        $out = tempnam(sys_get_temp_dir(), 'a1out');

        try {
            file_put_contents($in, $data);

            $ok = openssl_pkcs7_sign(
                $in,
                $out,
                $certPem,
                $keyPem,
                [],
                PKCS7_BINARY | PKCS7_DETACHED,
            );

            if (!$ok) {
                throw new RuntimeException('openssl_pkcs7_sign failed: ' . openssl_error_string());
            }

            return $this->extractDer((string) file_get_contents($out));
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** Extracts the DER blob from the application/x-pkcs7-signature S/MIME part. */
    private function extractDer(string $smime): string
    {
        $pattern = '/Content-Type:\s*application\/x-pkcs7-signature.*?\r?\n\r?\n(.*?)\r?\n-{2,}/s';

        if (!preg_match($pattern, $smime, $m)) {
            throw new RuntimeException('PKCS#7 block not found in the S/MIME output.');
        }

        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);

        if ($der === false || $der === '') {
            throw new RuntimeException('Invalid PKCS#7 base64 payload.');
        }

        return $der;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function pdfDate(): string
    {
        return 'D:' . date('YmdHis') . "+00'00'";
    }

    private function escapeString(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
