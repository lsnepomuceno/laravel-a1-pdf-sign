<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Data\SecurityStore;

/**
 * Reads the Document Security Store a B-LT or B-LTA document carries.
 *
 * The package has written this structure since 2.0 and never read it back, so
 * a document it produced could not be asked whether it carried what its own
 * profile promises. See
 * docs/decisions/0010-validation-consumes-what-signing-writes.md.
 *
 * @internal
 */
final readonly class SecurityStoreReader
{
    /**
     * Null when the document has no store at all, which is the ordinary case
     * for legacy, B-B and B-T. An empty store and an absent one are different
     * answers and are not collapsed.
     */
    public function read(string $pdf): ?SecurityStore
    {
        // Always the last one: a document signed more than once carries a store
        // per revision, and the newest supersedes the ones before it
        // (docs/spec/invariants.md).
        if (preg_match_all('/<<\s*\/Type\s*\/DSS\b/', $pdf, $found, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $offsets = $found[0];
        $last = end($offsets);

        if (! is_array($last)) {
            return null;
        }

        $start = (int) $last[1];
        $dictionary = $this->dictionaryAt($pdf, $start);

        return new SecurityStore(
            certificates: $this->countReferences($dictionary, 'Certs'),
            ocspResponses: $this->countReferences($dictionary, 'OCSPs'),
            crls: $this->countReferences($dictionary, 'CRLs'),
            signatureKeys: $this->vriKeys($dictionary),
        );
    }

    /**
     * The dictionary starting at $start, read by counting its own delimiters.
     *
     * A fixed window would have to be wide enough for the largest store and
     * would then swallow whatever follows the smallest, and /VRI nests, so the
     * first `>>` is not the end.
     */
    private function dictionaryAt(string $pdf, int $start): string
    {
        $depth = 0;
        $length = strlen($pdf);

        for ($position = $start; $position < $length - 1; $position++) {
            $pair = substr($pdf, $position, 2);

            if ($pair === '<<') {
                $depth++;
                $position++;

                continue;
            }

            if ($pair === '>>') {
                $depth--;
                $position++;

                if ($depth === 0) {
                    return substr($pdf, $start, $position - $start + 1);
                }
            }
        }

        return substr($pdf, $start);
    }

    /**
     * How many indirect references an array-valued entry holds.
     */
    private function countReferences(string $dictionary, string $key): int
    {
        if (preg_match('/\/' . $key . '\s*\[([^\]]*)\]/', $dictionary, $found) !== 1) {
            return 0;
        }

        $references = preg_match_all('/\d+\s+\d+\s+R/', $found[1]);

        return $references === false ? 0 : $references;
    }

    /**
     * @return list<string>
     */
    private function vriKeys(string $dictionary): array
    {
        if (preg_match('/\/VRI\s*<<(.*?)>>\s*(?=\/|>>)/s', $dictionary, $found) !== 1) {
            return [];
        }

        preg_match_all('/\/([0-9A-Fa-f]{40})\b/', $found[1], $keys);

        return array_map(strtoupper(...), $keys[1]);
    }
}
