<?php

use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

/**
 * Certification, checked by a reader that enforces it rather than by us.
 *
 * `tests/CertificationTest.php` asserts what this package **writes**: that
 * `certify()` emits the /DocMDP transform, that /Perms names the signature
 * that made it, that a second certification is refused. Necessary, and nowhere
 * near sufficient. A reader that ignored every one of those would pass all of
 * them, and whether a reader honours a certification is exactly what varies
 * between readers.
 *
 * poppler cannot answer it. `pdfsig` reports signatures, their coverage and
 * their validity, and says nothing about the permissions a certification
 * imposes, so a document certified at no-changes and then modified reads the
 * same to it as one that was never certified
 * (docs/decisions/0012-certification-signatures.md).
 *
 * pyHanko does answer it. It compares the appended revisions against the
 * policy and reports whether they were permitted
 * (docs/decisions/0031-certification-verified-by-a-reader.md).
 *
 * ```bash
 * docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest --group=pyhanko
 * ```
 */

/**
 * Certifies `test.pdf` at the given level and hands back the path plus a trust
 * anchor for the throwaway certificate that signed it.
 *
 * @return array{0: string, 1: string}
 */
function certifiedFor(CertificationLevel $level): array
{
    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify($level)
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    return [$path, trustAnchorFrom($pfxPath, $password)];
}

beforeEach(function () {
    // The development image installs pyHanko, and so does CI, so this should
    // never fire. It stays for the machine that runs the suite outside the
    // container, where a named skip reads better than a "pyhanko: not found".
    //
    // It cannot hide: composer test carries --fail-on-skipped, so a skipped
    // enforcement check fails the run rather than passing quietly.
    if (trim((string) shell_exec('command -v pyhanko')) === '') {
        test()->markTestSkipped('pyHanko is not installed; run the suite through .docker');
    }
});

it('is read as covering the whole document when nothing followed the certification', function (CertificationLevel $level) {
    [$path, $trust] = certifiedFor($level);

    expect(pyHankoJudgesValid($path, $trust))->toBeTrue()
        ->and(pyHankoReport($path, $trust))->toContain('The signature covers the entire file')
        ->and(pyHankoReportsPolicyViolation($path, $trust))->toBeFalse();
})->with([CertificationLevel::NoChanges, CertificationLevel::FormFilling])->group('pyhanko');

it('reports a certification broken by a later revision, which is the whole point', function () {
    // The fixture is committed rather than produced here, because producing it
    // needs a tool that will write a revision a certification forbids, and
    // pyHanko itself refuses to: asked to sign over this exact document it
    // stops with "Author signature forbids all changes", which is the correct
    // behaviour and useless for building the adversarial case.
    //
    // tests/Resources/certified-then-modified.pdf is test.pdf certified at
    // no-changes by this package with samples/certificate.pfx, then given one
    // appended incremental update that resizes a page, written with pyHanko's
    // IncrementalPdfFileWriter:
    //
    //   w = IncrementalPdfFileWriter(open(src, 'rb'))
    //   page = w.root['/Pages']['/Kids'][0].get_object()
    //   page['/MediaBox'] = generic.ArrayObject([0, 0, 300, 300])
    //   w.update_container(page)
    //   w.write(open(dst, 'wb'))
    //
    // Resizing a page is not a form-filling or signing operation, so /P 1
    // forbids it and so does /P 2.
    $path = resource('certified-then-modified.pdf');
    $trust = trustAnchorFrom(sample('certificate.pfx'), samplePassword());

    expect(pyHankoReportsPolicyViolation($path, $trust))->toBeTrue()
        ->and(pyHankoReport($path, $trust))->toContain('The signature is judged INVALID');
})->group('pyhanko');

it('accepts a further signature the certification level permits', function () {
    [$pfxPath, $password] = debugCertificate();

    $certified = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify(CertificationLevel::FormFilling)
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    $signedAgain = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($certified)
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    $trust = trustAnchorFrom($pfxPath, $password);

    // Form filling and signing are what /P 2 permits, so an outside reader
    // has to accept the second signature rather than merely tolerate it.
    expect(pyHankoReportsPolicyViolation($signedAgain, $trust))->toBeFalse()
        ->and(pyHankoReport($signedAgain, $trust))
        ->toContain('compatible with the current document modification policy');
})->group('pyhanko');

it('is read as valid by an outside tool at every profile the suite can produce offline', function () {
    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->seal()
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    expect(pyHankoJudgesValid($path, trustAnchorFrom($pfxPath, $password)))->toBeTrue();
})->group('pyhanko');
