<?php

/**
 * Rebuilds the two PDF 1.5 fixtures, with the /Resources qpdf was repairing.
 *
 * ISO 32000-1 §7.7.3.3 requires /Resources somewhere in the page tree, and
 * neither fixture had it, so qpdf warned and repaired both. That warning
 * travelled into every sample derived from them, which is why
 * tests/StructureTest.php filtered its verdict down to errors: a compromise
 * that switched the gate off for warnings everywhere else too.
 *
 * Object numbering, sizes and structure are preserved deliberately. Tests pin
 * `size` and the cross-reference keys, and the point of these fixtures is the
 * PDF 1.5 structures rather than their contents.
 *
 * Usage: php poc/rebuild-stream-fixtures.php
 */

$resources = __DIR__ . '/../tests/Resources';

/**
 * A cross-reference stream over the given entries, with W [1 2 1].
 *
 * @param  list<array{0: int, 1: int, 2: int}>  $entries
 */
function xrefStream(int $number, array $entries, int $size, bool $deflate): string
{
    $data = '';

    foreach ($entries as $entry) {
        $data .= chr($entry[0]) . pack('n', $entry[1]) . chr($entry[2]);
    }

    $filter = '';

    if ($deflate) {
        $data = (string) gzcompress($data);
        $filter = '/Filter/FlateDecode';
    }

    return "{$number} 0 obj\n"
        . "<</Type/XRef/Size {$size}/W[1 2 1]/Root 1 0 R{$filter}/Length " . strlen($data)
        . ">>\nstream\n"
        . $data
        . "\nendstream\nendobj\n";
}

/*
|--------------------------------------------------------------------------
| xref-stream.pdf: every object at the top level, indexed by a stream
|--------------------------------------------------------------------------
*/

$bodies = [
    1 => '<</Type/Catalog/Pages 2 0 R>>',
    2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
    3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]/Resources<<>>/Contents 4 0 R>>',
];

$content = '20 20 160 160 re f';
$bodies[4] = "<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream";

$pdf = "%PDF-1.5\n";
$entries = [[0, 0, 0]];

foreach ($bodies as $number => $body) {
    $entries[] = [1, strlen($pdf), 0];
    $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
}

$start = strlen($pdf);
$entries[] = [1, $start, 0];

$pdf .= xrefStream(5, $entries, 6, deflate: true) . "startxref\n{$start}\n%%EOF\n";

file_put_contents("{$resources}/xref-stream.pdf", $pdf);
printf("xref-stream.pdf   %d bytes\n", strlen($pdf));

/*
|--------------------------------------------------------------------------
| object-stream.pdf: the catalog, the page tree and the page packed away
|--------------------------------------------------------------------------
*/

$packed = [
    1 => '<</Type/Catalog/Pages 2 0 R>>',
    2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
    3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]/Resources<<>>/Contents 4 0 R>>',
];

// An object stream is a pair table followed by the objects themselves, and
// /First is where the second part begins (§7.5.7).
$pairs = '';
$objects = '';

foreach ($packed as $number => $body) {
    $pairs .= "{$number} " . strlen($objects) . ' ';
    $objects .= $body . ' ';
}

$pairs = rtrim($pairs) . "\n";
$stream = (string) gzcompress($pairs . $objects);

$content = '20 20 160 160 re f';

$pdf = "%PDF-1.5\n";
$offsets = [];

$offsets[4] = strlen($pdf);
$pdf .= "4 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";

$offsets[5] = strlen($pdf);
$pdf .= "5 0 obj\n"
    . '<</Type/ObjStm/N ' . count($packed) . '/First ' . strlen($pairs) . '/Filter/FlateDecode/Length ' . strlen($stream)
    . ">>\nstream\n"
    . $stream
    . "\nendstream\nendobj\n";

$start = strlen($pdf);

$pdf .= xrefStream(6, [
    [0, 0, 0],
    [2, 5, 0],
    [2, 5, 1],
    [2, 5, 2],
    [1, $offsets[4], 0],
    [1, $offsets[5], 0],
    [1, $start, 0],
], 7, deflate: false) . "startxref\n{$start}\n%%EOF\n";

file_put_contents("{$resources}/object-stream.pdf", $pdf);
printf("object-stream.pdf %d bytes\n", strlen($pdf));
