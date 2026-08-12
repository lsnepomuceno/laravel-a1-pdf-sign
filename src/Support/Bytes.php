<?php

namespace LSNepomuceno\LaravelA1PdfSign\Support;

/**
 * Byte surgery on a document already in memory.
 *
 * `substr_replace()` builds a whole new string, which on a 200 MB plan means a
 * second 200 MB allocation to change twenty characters. Both replacements the
 * signing pipeline performs are **fixed width by design**, because the whole
 * point of the placeholders is that no offset moves, so neither needs a new
 * string at all.
 *
 * Measured on a 25 MB document, replacing 32 KB in the middle: `substr_replace`
 * peaks at 52 MB, this peaks at 27 MB.
 *
 * **The reference is load-bearing.** PHP separates a string before mutating it
 * when anything else still points at the same one, so passing by value would
 * copy exactly as before and quietly do nothing. That is why this takes
 * `&$subject`, and why every caller assigns nothing back.
 *
 * There is no framework helper for this: `Str::substrReplace()` is multibyte
 * aware, which over PDF bytes computes the wrong offsets entirely
 * (docs/spec/conventions.md).
 */
final readonly class Bytes
{
    /**
     * Writes $replacement over $subject at $offset, changing nothing else.
     *
     * The caller is responsible for the two things this cannot check cheaply:
     * that the span fits, and that it is the span it meant.
     */
    public static function overwrite(string &$subject, string $replacement, int $offset): void
    {
        $length = strlen($replacement);

        for ($i = 0; $i < $length; $i++) {
            $subject[$offset + $i] = $replacement[$i];
        }
    }
}
