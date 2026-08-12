<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

/**
 * Splits a revision's objects into runs of consecutive numbers.
 *
 * Both cross-reference forms express the same idea. The classic table of
 * §7.5.4 opens each subsection with "first count"; the stream of §7.5.8 lists
 * the same pairs in /Index. A revision touches the catalog and a page low in
 * the file and its new objects high in it, so the numbers are never one
 * unbroken run, and declaring them as one would misplace every entry past the
 * gap.
 *
 * @internal
 */
final readonly class XrefSubsections
{
    /**
     * @param  array<int, int>  $offsets  Object number to byte offset.
     * @return list<array<int, int>> Each run, keys preserved.
     */
    public function of(array $offsets): array
    {
        ksort($offsets);

        $groups = [];
        $current = [];
        $previous = null;

        foreach ($offsets as $number => $offset) {
            if ($previous !== null && $number !== $previous + 1) {
                $groups[] = $current;
                $current = [];
            }

            $current[$number] = $offset;
            $previous = $number;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }
}
