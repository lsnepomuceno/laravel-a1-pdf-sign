<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

/**
 * Reads a PDF dictionary by counting its own delimiters.
 *
 * Two places need this and both had their own copy: the Document Security Store
 * reader and the signature field reader. Neither could be tested precisely,
 * because both only ever asked the result for counts, and a boundary off by a
 * byte gives the same count. Here it is one implementation whose exact output a
 * test can assert.
 *
 * **A fixed window does not work, and neither does the first `>>`.** A window
 * wide enough for the largest dictionary swallows whatever follows the
 * smallest, and dictionaries nest: `/VRI` inside a store, `/DR` inside an
 * interactive form. Stopping at the first closing marker returns a fragment
 * that parses as if the rest were absent, which is worse than failing.
 */
final readonly class PdfDictionary
{
    /**
     * The dictionary that opens at $start, including both delimiters.
     *
     * Null when it never closes, which is a truncated file rather than a
     * dictionary this cannot read. The caller decides what to do with the
     * remainder: the store reader parses what it has, the field reader treats
     * the form as unreadable.
     */
    public function at(string $contents, int $start): ?string
    {
        if (substr($contents, $start, 2) !== '<<') {
            return null;
        }

        $depth = 0;
        $length = strlen($contents);

        for ($position = $start; $position < $length - 1; $position++) {
            $pair = substr($contents, $position, 2);

            if ($pair === '<<') {
                $depth++;
                // Past the second delimiter character, so "<<<<" reads as two
                // openings rather than three overlapping ones.
                $position++;

                continue;
            }

            if ($pair === '>>') {
                $depth--;
                $position++;

                if ($depth === 0) {
                    return substr($contents, $start, $position - $start + 1);
                }
            }
        }

        return null;
    }
}
