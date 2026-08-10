<?php

use LSNepomuceno\LaravelA1PdfSign\Support\PdfStream;

/**
 * The stream payload reader, tested on its own rather than through whichever
 * structure happens to use it.
 *
 * Both callers, the cross-reference stream and the object stream, only ever ask
 * it for something they then parse further, so a byte lost at either end shows
 * up as a parse failure somewhere else, if at all. See
 * docs/decisions/0015-object-streams.md.
 */
function streamObject(string $dictionary, string $payload, string $eol = "\n"): string
{
    return "1 0 obj\n{$dictionary}{$eol}stream{$eol}{$payload}\nendstream\nendobj\n";
}

it('returns the payload of an unfiltered stream', function () {
    $pdf = streamObject('<</Length 5>>', 'HELLO');

    expect(new PdfStream()->contentsAt($pdf, 0, '<</Length 5>>'))->toBe('HELLO');
});

it('decodes a flate stream', function () {
    $payload = (string) gzcompress('packed bytes');
    $dictionary = '<</Filter/FlateDecode/Length ' . strlen($payload) . '>>';

    expect(new PdfStream()->contentsAt(streamObject($dictionary, $payload), 0, $dictionary))
        ->toBe('packed bytes');
});

it('decodes an ASCII hex stream', function () {
    $dictionary = '<</Filter/ASCIIHexDecode/Length 11>>';
    $pdf = streamObject($dictionary, '48454C4C4F>');

    expect(new PdfStream()->contentsAt($pdf, 0, $dictionary))->toBe('HELLO');
});

it('accepts CRLF after the keyword as well as LF', function () {
    // ISO 32000-1 §7.3.8.1: "stream" is followed by CRLF or LF and by nothing
    // else, so treating CRLF as one byte would take the payload one short.
    $pdf = streamObject('<</Length 5>>', 'HELLO', "\r\n");

    expect(new PdfStream()->contentsAt($pdf, 0, '<</Length 5>>'))->toBe('HELLO');
});

it('drops the end-of-line that belongs to the syntax, not to the data', function () {
    // ISO 32000-1 §7.3.8.1. Keeping it corrupts an unfiltered payload by a
    // byte, which a filtered one tolerates and so hides.
    $pdf = streamObject('<</Type/Whatever>>', 'exactly this', "\r\n");

    expect(new PdfStream()->contentsAt($pdf, 0, '<</Type/Whatever>>'))->toBe('exactly this');
});

it('falls back to the endstream keyword when no length is declared', function () {
    $pdf = streamObject('<</Filter/FlateDecode>>', (string) gzcompress('no length here'));

    expect(new PdfStream()->contentsAt($pdf, 0, '<</Filter/FlateDecode>>'))->toBe('no length here');
});

it('falls back when the length is an indirect reference', function () {
    // A /Length pointing at another object is legal and is not resolved here.
    // Reading "7" out of "/Length 7 0 R" would take seven bytes of a stream
    // that is longer, which parses as corruption rather than as a short read.
    $dictionary = '<</Length 7 0 R>>';
    $pdf = streamObject($dictionary, 'the whole payload');

    expect(new PdfStream()->contentsAt($pdf, 0, $dictionary))->toBe('the whole payload');
});

it('reads the declared length even when it stops short of endstream', function () {
    $pdf = streamObject('<</Length 5>>', 'HELLOAND MORE');

    expect(new PdfStream()->contentsAt($pdf, 0, '<</Length 5>>'))->toBe('HELLO');
});

it('answers null for a filter it does not decode', function () {
    $dictionary = '<</Filter/LZWDecode/Length 4>>';

    expect(new PdfStream()->contentsAt(streamObject($dictionary, 'junk'), 0, $dictionary))->toBeNull();
});

it('answers null when the payload does not decode', function () {
    $dictionary = '<</Filter/FlateDecode/Length 8>>';

    expect(new PdfStream()->contentsAt(streamObject($dictionary, 'not flate'), 0, $dictionary))->toBeNull();
});

it('answers null when the object carries no stream at all', function () {
    expect(new PdfStream()->contentsAt("1 0 obj\n<</Type/Catalog>>\nendobj\n", 0, '<</Type/Catalog>>'))
        ->toBeNull();
});

it('answers null when the dictionary is not where it was said to be', function () {
    expect(new PdfStream()->contentsAt(streamObject('<</Length 5>>', 'HELLO'), 0, '<</Length 9>>'))
        ->toBeNull();
});

it('reads the dictionary of the object at an offset', function () {
    $pdf = "%PDF-1.5\n1 0 obj\n<</Type/ObjStm/N 3>>\nstream\n";

    expect(new PdfStream()->dictionaryAt($pdf, 9))->toBe('<</Type/ObjStm/N 3>>')
        ->and(new PdfStream()->dictionaryAt("1 0 obj\nendobj\n", 0))->toBeNull();
});
