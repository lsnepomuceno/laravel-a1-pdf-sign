<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;

/**
 * What this package writes, against the specification's own grammar.
 *
 * The Arlington PDF Model is the PDF Association's machine-readable ISO 32000:
 * 3465 TSV files describing every dictionary, key, type, required-ness and the
 * version each was introduced in. `TestGrammar` checks a document against it.
 *
 * It asks what nothing else here asks. qpdf asks whether the syntax closes, the
 * offsets line up and the streams decode. veraPDF decides PDF/A and PDF/UA.
 * This asks whether each object **is the object the specification describes**,
 * which for a revision writer assembling dictionaries by string concatenation
 * is the check nobody was running
 * (docs/decisions/0037-what-we-write-against-the-grammar.md).
 *
 * **A zero here means "nothing found on the paths it walked", not "clean".**
 * Its traversal reaches the signature dictionary through `/Perms/DocMDP` rather
 * than through the widget's `/V`, so the counts are asserted per file instead
 * of as one global zero, which would claim more than the tool delivers.
 */

/**
 * The errors TestGrammar reports for a document.
 *
 * @return list<string>
 */
function arlingtonErrors(string $path, string $extensions = ''): array
{
    $report = app(ProcessRunner::class)->run(sprintf(
        'testgrammar --tsvdir %s --brief --no-color %s--pdf %s 2>&1 || true',
        escapeshellarg(arlingtonModel()),
        $extensions === '' ? '' : '--extensions ' . escapeshellarg($extensions) . ' ',
        escapeshellarg($path),
    ));

    $errors = [];

    foreach (explode("\n", $report) as $line) {
        if (str_starts_with(trim($line), 'Error:')) {
            $errors[] = trim($line);
        }
    }

    return $errors;
}

function arlingtonModel(): string
{
    $configured = getenv('ARLINGTON_TSV');

    return $configured === false || $configured === '' ? '/opt/arlington/tsv/latest' : $configured;
}

beforeEach(function () {
    // Installed in the development image and in CI, so this should never fire.
    // It stays for the machine running the suite outside the container, and it
    // cannot hide: composer test carries --fail-on-skipped.
    if (trim((string) shell_exec('command -v testgrammar')) === '') {
        test()->markTestSkipped('TestGrammar is not installed; run the suite through .docker');
    }
});

it('agrees the unsigned input is describable before anything is done to it', function () {
    // A baseline that already disagreed with the grammar would make every
    // verdict below meaningless, and would look like this package's fault.
    expect(arlingtonErrors(resource('test.pdf')))->toBe([]);
})->group('arlington');

it('writes objects the specification describes', function (string $sample) {
    expect(arlingtonErrors(sample("{$sample}.pdf")))->toBe([]);
})->with([
    'legacy',
    'pades-b-b',
    'pades-b-t',
    'pades-b-lt',
    'pades-b-lta',
    'two-seals',
    'six-signatures',
    'signed-into-fields',
    'object-stream',
    'xref-stream',
])->group('arlington');

it('reports the certified document, which is the one finding it has made', function () {
    // /SubFilter /ETSI.CAdES.detached became standard in PDF 2.0. Below that it
    // is the ETSI_PAdES developer extension, which ISO 32000-1 §7.12 says a
    // file declares in the catalog's /Extensions. The samples carry a
    // %PDF-1.4 header and no /Extensions.
    //
    // Asserted rather than fixed, so the day it is fixed this test fails and
    // has to be updated, which is how the PDF/UA clauses were handled.
    $errors = arlingtonErrors(sample('certified.pdf'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('SubFilter')
        ->and($errors[0])->toContain('ETSI.CAdES.detached');
})->group('arlington');

it('clears that finding when the extension is declared, which identifies the fix', function () {
    // Isolated rather than guessed: enabling the extension the message names
    // makes the error go away, and so does forcing the version to 2.0.
    expect(arlingtonErrors(sample('certified.pdf'), 'ETSI_PAdES'))->toBe([]);
})->group('arlington');
