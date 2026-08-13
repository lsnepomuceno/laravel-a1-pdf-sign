<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

/**
 * PDF/A conformance, measured rather than reasoned about.
 *
 * `tests/Conformance/PdfAConformanceTest.php` asserts the structures each verdict turned
 * on, which is what the rest of the suite can check. This file asks veraPDF,
 * the reference validator, and is the only place a conformance claim is
 * actually established.
 *
 * It exists because reasoning failed once already: an invisible signature
 * "obviously" preserved conformance and every combination failed, on a missing
 * trailer /ID nobody would have looked for
 * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
 *
 * `veraPdfVerdict()` lives in tests/Pest.php, because a second file needs it
 * now and a helper defined in one test file is invisible to the others.
 *
 * veraPDF is installed in the development image and in CI, so this runs with
 * the rest of the suite and never skips:
 *
 * ```bash
 * docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest --group=pdfa
 * ```
 */

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
    // The development image installs veraPDF, and so does CI, so this should
    // never fire. It stays for the machine that runs the suite outside the
    // container: a named skip reads better than a "verapdf: not found" from the
    // process runner.
    //
    // It cannot hide either. `composer test` carries --fail-on-skipped, so a
    // skipped conformance check fails the run rather than passing quietly
    // (docs/spec/quality-policy.md).
    if (trim((string) shell_exec('command -v verapdf')) === '') {
        test()->markTestSkipped('veraPDF is not installed; run the suite through .docker');
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

it('keeps a PDF/A document conformant with a seal drawn on it', function (string $flavour) {
    // This asserted FAIL until 0028, and the comment said the day it flipped was
    // the day someone should be told. The seal now carries its own ICCBased
    // profile instead of asking the document for an OutputIntent it may not
    // have (docs/decisions/0028-the-seal-carries-its-own-colour-space.md).
    $path = signedPdfA($flavour, seal: true, transparent: false);

    expect(veraPdfVerdict($path, $flavour))->toBe('PASS');

    unlink($path);
})->with(['1b', '2b'])->group('pdfa');

it('keeps PDF/A-2 conformant with a transparent seal, which part 1 cannot be', function () {
    // Part 2 allows transparency, and the last rule standing was §6.2.10: the
    // page needs a group naming its blending colour space. veraPDF named that
    // rule and nothing else, so the group closed it.
    $path = signedPdfA('2b', seal: true, transparent: true);

    expect(veraPdfVerdict($path, '2b'))->toBe('PASS');

    unlink($path);
})->group('pdfa');

it('never conforms to PDF/A-1 with a transparent seal, whatever else changes', function () {
    // ISO 19005-1 §6.4 forbids /SMask outright, so no arrangement of this
    // package's output makes a transparent seal conformant to part 1. It is the
    // one cell the colour space could not rescue, and seal.transparent is the
    // lever, which is why it exists.
    $path = signedPdfA('1b', seal: true, transparent: true);

    expect(veraPdfVerdict($path, '1b'))->toBe('FAIL');

    unlink($path);
})->group('pdfa');

it('keeps a PDF/A document conformant at pades-b-lta', function (string $flavour) {
    // The cell that matters most for an archive and the only one that was never
    // measured: PDF/A plus long-term validation is the canonical "keep this for
    // twenty years" artefact.
    //
    // In the network group because a B-LTA document cannot be produced without
    // reaching a timestamp authority, so this is reported rather than blocking,
    // on the same terms as every other test that needs one. Unmeasured was the
    // alternative (docs/decisions/0025-what-signing-does-to-pdf-a.md).
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource("pdfa-{$flavour}.pdf"))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    // The archive timestamp is a form field of its own, and ISO 19005-1 §6.9
    // wants every one of them to have an appearance dictionary. It had none:
    // samples/pades-b-lta.pdf still shows Timestamp2 with /Rect[0 0 0 0] and no
    // /AP, beside a signature widget that has one.
    expect($signed->contents)->toMatch('#/Rect\[0 0 0 0\]/AP<</N \d+ 0 R>>/T \(Timestamp#');

    $path = $signed->save(A1PdfSign::tempPath(true, '.pdf'));

    expect(veraPdfVerdict($path, $flavour))->toBe('PASS');

    unlink($path);
})->with(['1b', '2b'])->group('network');

it('keeps a PDF/A document conformant at pades-b-t', function () {
    // One level down, where the token rides inside the CMS rather than in a
    // revision of its own, so nothing is added to the page at all.
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-2b.pdf'))
        ->profile(SignatureProfile::PadesBT)
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    expect(veraPdfVerdict($path, '2b'))->toBe('PASS');

    unlink($path);
})->group('network');
