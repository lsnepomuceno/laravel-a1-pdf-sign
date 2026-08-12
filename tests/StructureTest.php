<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

/**
 * The structure of what the revision writer emits, checked by qpdf.
 *
 * This package writes cross-reference tables and streams by hand, and poppler
 * is lenient about both: a table whose offsets are slightly wrong still opens,
 * and a reader that recovers by scanning the file hides the fault entirely.
 * qpdf is strict, and it reads the same structures for a different reason, so
 * it disagrees where poppler forgives.
 *
 * **A development and validation instrument only.** qpdf is never invoked by
 * `src/`, and `tests/ArchTest.php` fails if that changes: a consuming
 * application installs a signing library, not a toolchain.
 *
 * `qpdfCheck()` and `qpdfComplaintsAbout()` live in tests/Pest.php, because
 * tests/EncryptedDocumentTest.php needs them too and a helper defined inside
 * one test file is invisible to the others under --parallel.
 *
 * See docs/spec/quality-policy.md.
 */

/**
 * Whether qpdf finds nothing wrong at all, which is stronger than finding
 * nothing new.
 */
function qpdfIsClean(string $path): bool
{
    // Matched on the prefix: the sentence ends "errors found" in qpdf 11 and
    // "errors detected" in some builds, and the gate should not turn on which.
    return str_contains(qpdfCheck($path), 'No syntax or stream encoding errors');
}

beforeEach(function () {
    // As with veraPDF: the image has it, and --fail-on-skipped means a skip
    // here fails the run rather than passing quietly.
    if (trim((string) shell_exec('command -v qpdf')) === '') {
        test()->markTestSkipped('qpdf is not installed; run the suite through .docker');
    }
});

it('never makes a document structurally worse than it found it', function (string $case, string $source) {
    // The gate is comparative on purpose. Two of the fixtures are minimal
    // documents whose pages carry no /Resources, which qpdf 12 warns about and
    // qpdf 11 did not, and that fault is in the input rather than in anything
    // written here. What must never happen is a complaint that was not there
    // before (docs/spec/invariants.md).
    [$pfxPath, $password] = debugCertificate();

    $before = qpdfComplaintsAbout(resource($source));

    $signed = match ($case) {
        'plain' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdf(resource('test.pdf'))->sign(),

        'sealed' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdf(resource('test.pdf'))
            ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120))->sign(),

        // The cross-reference stream path, which appends a stream rather than a
        // table and has to index itself.
        'xref-stream' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdf(resource('xref-stream.pdf'))->sign(),

        // Objects packed into an object stream, written back at the top level.
        'object-stream' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdf(resource('object-stream.pdf'))->sign(),

        // A predictor-compressed cross-reference stream.
        'predictor' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdf(resource('xref-stream-predictor.pdf'))->sign(),

        // Filling a field the template already carries, which rewrites an
        // existing object rather than adding one.
        'into-field' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdfContents(template(), 'contract.pdf')
            ->intoField('SignatureManager')->seal()->sign(),

        'certified' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdf(resource('test.pdf'))->certify()->lock()->sign(),

        'legacy' => A1PdfSign::newSignature()->certificate($pfxPath, $password)
            ->pdf(resource('test.pdf'))->profile(SignatureProfile::Legacy)->sign(),

        // Unreachable: the dataset names every case. Present because a match
        // over a string has to be told so.
        default => throw new InvalidArgumentException("no such case: {$case}"),
    };

    $path = $signed->save(A1PdfSign::tempPath(true, '.pdf'));

    expect(array_diff(qpdfComplaintsAbout($path), $before))->toBe([])
        // And a sound input stays sound outright, which is the stronger half.
        ->and($before === [] ? qpdfIsClean($path) : true)->toBeTrue();

    unlink($path);
})->with([
    'plain' => ['plain', 'test.pdf'],
    'sealed' => ['sealed', 'test.pdf'],
    'xref-stream' => ['xref-stream', 'xref-stream.pdf'],
    'object-stream' => ['object-stream', 'object-stream.pdf'],
    'predictor' => ['predictor', 'xref-stream-predictor.pdf'],
    'into-field' => ['into-field', 'signature-fields.pdf'],
    'certified' => ['certified', 'test.pdf'],
    'legacy' => ['legacy', 'test.pdf'],
]);

it('keeps every revision sound as they stack up', function () {
    // The case the whole signer is built around: each signature appends, and
    // a table whose /Prev chain is wrong shows here rather than three
    // revisions later (docs/spec/invariants.md).
    [$pfxPath, $password] = debugCertificate();

    $pdf = LSNepomuceno\LaravelA1PdfSign\Support\Files::read(resource('test.pdf'));

    for ($round = 1; $round <= 4; $round++) {
        $pdf = A1PdfSign::newSignature()
            ->certificate($pfxPath, $password)
            ->pdfContents($pdf, 'contract.pdf')
            ->info(name: "Signer {$round}")
            ->seal(placement: new SealPlacement(x: 30 * $round, y: 300, width: 60))
            ->sign()
            ->contents;

        $path = A1PdfSign::tempPath(true, '.pdf');
        file_put_contents($path, $pdf);

        expect(qpdfIsClean($path))->toBeTrue();

        unlink($path);
    }
});

it('reports nothing at all about any committed sample', function () {
    // These are what readers are pointed at, in Adobe and in ITI Validar, so a
    // structural fault in one would be a fault in the evidence.
    //
    // This filtered down to errors until the fixtures were rebuilt. Two samples
    // descend from minimal PDF 1.5 files whose page objects carried no
    // /Resources, which ISO 32000-1 §7.7.3.3 requires somewhere in the page
    // tree, so qpdf warned and repaired. That one warning was reason enough to
    // ignore warnings on every other sample too, which is how a compromise in
    // one place turns off a gate everywhere. `poc/rebuild-stream-fixtures.php`
    // regenerates them, and the verdict is now the whole verdict.
    $samples = glob(dirname(__DIR__) . '/samples/*.pdf');

    foreach ($samples === false ? [] : $samples as $sample) {
        expect(qpdfComplaintsAbout($sample))->toBe([]);
    }
});
