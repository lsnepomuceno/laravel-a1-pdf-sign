<?php

use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;

/**
 * PDF/A conformance, measured rather than reasoned about.
 *
 * `tests/PdfAConformanceTest.php` asserts the structures each verdict turned
 * on, which is what the rest of the suite can check. This file asks veraPDF,
 * the reference validator, and is the only place a conformance claim is
 * actually established.
 *
 * It exists because reasoning failed once already: an invisible signature
 * "obviously" preserved conformance and every combination failed, on a missing
 * trailer /ID nobody would have looked for
 * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
 *
 * ```bash
 * docker compose -f .docker/compose.yaml run --rm pdfa vendor/bin/pest --group=pdfa
 * ```
 */
function veraPdfVerdict(string $path, string $flavour): string
{
    // "|| true" because veraPDF exits 1 for a non-conformant file, which is a
    // verdict rather than a failure to run. The verdict itself is the first
    // word of stdout either way.
    $output = app(ProcessRunner::class)->run(sprintf(
        'verapdf --format text -f %s %s 2>&1 || true',
        escapeshellarg($flavour),
        escapeshellarg($path),
    ));

    return str_starts_with(trim($output), 'PASS') ? 'PASS' : 'FAIL';
}

/**
 * Signs a baseline and hands back the path, so veraPDF can be pointed at it.
 */
function signedPdfA(string $flavour, bool $seal, bool $transparent = true): string
{
    [$pfxPath, $password] = debugCertificate();

    config()->set('a1-pdf-sign.seal.transparent', $transparent);

    $pending = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource("pdfa-{$flavour}.pdf"));

    if ($seal) {
        $pending->seal(placement: new SealPlacement(x: 60, y: 400, width: 120));
    }

    return $pending->sign()->save(A1PdfSign::tempPath(true, '.pdf'));
}

beforeEach(function () {
    // Every service but `pdfa` is a PHP image without a JRE, so the group skips
    // rather than failing when the validator is not installed.
    if (trim((string) shell_exec('command -v verapdf')) === '') {
        test()->markTestSkipped('veraPDF is not installed; use the pdfa compose service');
    }
});

it('agrees the baselines are conformant before anything is done to them', function (string $flavour) {
    // A baseline that stopped being conformant would make every verdict below
    // meaningless, and it would look like this package's fault.
    expect(veraPdfVerdict(resource("pdfa-{$flavour}.pdf"), $flavour))->toBe('PASS');
})->with(['1b', '2b'])->group('pdfa');

it('keeps a PDF/A document conformant when the signature is invisible', function (string $flavour) {
    // The claim this package makes for a PDF/A workflow. Both of these failed
    // on the trailer /ID until it was carried into the appended revision.
    $path = signedPdfA($flavour, seal: false);

    expect(veraPdfVerdict($path, $flavour))->toBe('PASS');

    unlink($path);
})->with(['1b', '2b'])->group('pdfa');

it('loses conformance when a seal is drawn, which is the colour space', function (string $flavour) {
    // Asserted as FAIL on purpose. The seal is DeviceRGB, which both parts
    // allow only where the file carries an RGB OutputIntent, and adding one is
    // the author's statement about their document rather than the signer's.
    //
    // The day the seal moves to an ICCBased space this test fails, and that is
    // exactly when someone should be told.
    $path = signedPdfA($flavour, seal: true, transparent: false);

    expect(veraPdfVerdict($path, $flavour))->toBe('FAIL');

    unlink($path);
})->with(['1b', '2b'])->group('pdfa');

it('never conforms to PDF/A-1 with a transparent seal, whatever else changes', function () {
    // ISO 19005-1 §6.4 forbids /SMask outright, so no arrangement of this
    // package's output makes a transparent seal conformant to part 1.
    // seal.transparent is the lever, and this is why it exists.
    $path = signedPdfA('1b', seal: true, transparent: true);

    expect(veraPdfVerdict($path, '1b'))->toBe('FAIL');

    unlink($path);
})->group('pdfa');
