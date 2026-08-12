<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Data\SecurityStore;
use LSNepomuceno\LaravelA1PdfSign\Support\PdfDictionary;

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
    public function __construct(
        private PdfDictionary $dictionaries = new PdfDictionary(),
    ) {}

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

        // preg_match_all returned at least one match above, so the list is not
        // empty and the offset is not the -1 that a non-participating group
        // would report.
        /** @var non-empty-list<array{0: string, 1: int<0, max>}> $offsets */
        $offsets = $found[0];
        $start = end($offsets)[1];

        // A store the file cuts off is still worth reading: what is there is
        // there, and reporting nothing would be less true than reporting less.
        $dictionary = $this->dictionaries->at($pdf, $start) ?? substr($pdf, $start);

        return new SecurityStore(
            certificates: $this->countReferences($dictionary, 'Certs'),
            ocspResponses: $this->countReferences($dictionary, 'OCSPs'),
            crls: $this->countReferences($dictionary, 'CRLs'),
            signatureKeys: $this->vriKeys($dictionary),
        );
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
