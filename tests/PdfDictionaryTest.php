<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Support\PdfDictionary;

/**
 * The dictionary walker, asserted on the exact bytes it returns.
 *
 * Both callers previously had their own copy and only ever asked the result for
 * counts, which a boundary off by a byte gives identically. Asserting the
 * substring is what makes the boundary testable at all, and it is the reason
 * this is one class rather than two private methods.
 */
it('returns the dictionary and nothing after it', function () {
    // The trap: a fixed window wide enough for the largest dictionary swallows
    // whatever follows the smallest.
    $contents = '<</Type/DSS/Certs[1 0 R]>> <</Certs[2 0 R 3 0 R 4 0 R]>>';

    expect(new PdfDictionary()->at($contents, 0))->toBe('<</Type/DSS/Certs[1 0 R]>>');
});

it('closes at its own depth, not at the first marker it meets', function () {
    // Stopping at the first ">>" returns a fragment that parses as if the rest
    // were absent, which reads as a valid answer and is not one.
    $contents = '<</VRI<</AB<</Cert[1 0 R]>>>>/Certs[1 0 R]>>';

    expect(new PdfDictionary()->at($contents, 0))->toBe($contents);
});

it('reads a dictionary that starts partway through the string', function () {
    $contents = 'trailing junk <</Type/DSS>> more junk';

    expect(new PdfDictionary()->at($contents, 14))->toBe('<</Type/DSS>>');
});

it('reads consecutive openings as separate levels', function () {
    // "<<<<" is two openings, not three overlapping ones, which is what
    // advancing past the second delimiter character buys.
    $contents = '<<<</A 1>>>>';

    expect(new PdfDictionary()->at($contents, 0))->toBe($contents);
});

it('answers null when the dictionary never closes', function () {
    expect(new PdfDictionary()->at('<</Type/DSS/Certs[1 0 R]', 0))->toBeNull()
        ->and(new PdfDictionary()->at('<</A<</B 1>>', 0))->toBeNull();
});

it('answers null when nothing opens at the offset given', function () {
    expect(new PdfDictionary()->at('/Type/DSS>>', 0))->toBeNull()
        ->and(new PdfDictionary()->at('<</Type/DSS>>', 2))->toBeNull()
        ->and(new PdfDictionary()->at('', 0))->toBeNull()
        // One delimiter character is not an opening.
        ->and(new PdfDictionary()->at('<', 0))->toBeNull();
});

it('reads a dictionary that ends exactly at the end of the string', function () {
    // The loop stops at length - 1 because it reads two bytes at a time, so a
    // dictionary closing on the final byte is the case that catches an
    // off-by-one in the bound.
    expect(new PdfDictionary()->at('<</A 1>>', 0))->toBe('<</A 1>>');
});

it('keeps the empty dictionary distinct from an unclosed one', function () {
    expect(new PdfDictionary()->at('<<>>', 0))->toBe('<<>>');
});

it('does not go looking for a dictionary that starts elsewhere', function () {
    // Without the check at the offset, the walker would scan forward and return
    // a "dictionary" that begins with whatever preceded it. The caller asked
    // about this position, so a dictionary one byte later is not the answer.
    expect(new PdfDictionary()->at('x<</A 1>>', 0))->toBeNull()
        ->and(new PdfDictionary()->at('x<</A 1>>', 1))->toBe('<</A 1>>');
});
