<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\ArchiveExtender;
use LSNepomuceno\LaravelA1PdfSign\Testing\LocalTimestampAuthority;

/**
 * The profiles above pades-b-b, gated rather than reported.
 *
 * Everything B-T and above adds rides through `SignatureTransport`, and until
 * that was an interface the only way to exercise any of it was to reach
 * freetsa.org. That left the package's most important behaviour in the network
 * group: reported, never blocking, and dependent on somebody else's uptime.
 *
 * `Testing\LocalTimestampAuthority` answers with real RFC 3161 tokens produced
 * by `openssl ts -reply`, with no server and no connection, so these run in the
 * blocking suite. What it is not is a third party, which is the one thing the
 * live tests in PadesTest and DssTest still establish and these deliberately
 * do not.
 *
 * See docs/decisions/0027-the-transport-is-a-seam.md.
 */
beforeEach(function () {
    app()->bind(SignatureTransport::class, LocalTimestampAuthority::class);
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://timestamp.invalid/tsr');
});

it('signs at pades-b-t and verifies the token it embedded', function () {
    // The whole point of B-T, checked end to end without a network: the token
    // is produced here, embedded, read back out of the CMS and verified against
    // the signature value it stamps
    // (docs/decisions/0019-validation-reads-what-it-writes.md).
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBT)
        ->sign();

    $report = app(SignatureValidator::class)->validate($signed->contents);
    $signature = $report->latest();

    expect($report->isValid())->toBeTrue()
        ->and($signature?->timestampVerified)->toBeTrue()
        ->and($signature?->profile)->toBe(SignatureProfile::PadesBT)
        // genTime comes from the authority, so it is a time this package did
        // not choose, even when the authority is local.
        ->and($signature?->attestedAt())->toBeGreaterThan(0);
});

it('refuses a token that stamps something else, offline', function () {
    // The imprint check, which is what makes a timestamp mean anything: a token
    // is produced for one signature and offered for other bytes.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBT)
        ->sign();

    $signature = app(SignatureValidator::class)->validate($signed->contents)->latest();
    $cms = $signature->rawContents ?? '';
    $token = new LSNepomuceno\LaravelA1PdfSign\Validation\TimestampTokenReader()->read($cms);

    expect($token)->not->toBeNull();

    assert($token !== null);

    $verifier = app(LSNepomuceno\LaravelA1PdfSign\Validation\SignatureVerifier::class);

    expect($verifier->verifiedTimestampInfo($token['token'], $token['stamped']))->not->toBeNull()
        ->and($verifier->verifiedTimestampInfo($token['token'], 'not what was stamped'))->toBeNull();
});

it('closes with an archive timestamp at pades-b-lta', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    $report = app(SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->timestamps())->toHaveCount(1)
        ->and($report->latest()?->isTimestamp)->toBeTrue()
        ->and($report->latest()?->coversWholeDocument)->toBeTrue()
        // The archive timestamp is a form field too, and ISO 19005-1 §6.9 wants
        // every one of them to have an appearance
        // (docs/decisions/0025-what-signing-does-to-pdf-a.md).
        ->and($signed->contents)->toMatch('#/Rect\[0 0 0 0\]/AP<</N \d+ 0 R>>/T \(Timestamp#');
});

it('extends the archive chain, offline', function () {
    // 0022's behaviour, which could only ever be reported before: the previous
    // links survive byte for byte and the document then carries two timestamps.
    [$pfxPath, $password] = debugCertificate();

    $original = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign()
        ->contents;

    $extended = app(ArchiveExtender::class)->extend($original, 'archive.pdf');

    expect(substr($extended->contents, 0, strlen($original)))->toBe($original);

    $report = app(SignatureValidator::class)->validate($extended->contents);

    expect($report->timestamps())->toHaveCount(2)
        ->and($report->isValid())->toBeTrue()
        ->and($report->signatures[0]->profile)->toBe(SignatureProfile::PadesBLTA);
});

it('embeds a security store at pades-b-lt', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->sign();

    $report = app(SignatureValidator::class)->validate($signed->contents);

    expect($signed->contents)->toContain('/Type /DSS')
        ->and($report->latest()?->profile)->toBe(SignatureProfile::PadesBLT)
        ->and($report->isValid())->toBeTrue();
});

it('keeps a PDF/A document conformant at pades-b-lta, without a network', function (string $flavour) {
    // The cell that mattered most and could only be reported: PDF/A plus
    // long-term validation is the canonical twenty-year artefact. It is a gate
    // now (docs/decisions/0025-what-signing-does-to-pdf-a.md).
    if (trim((string) shell_exec('command -v verapdf')) === '') {
        test()->markTestSkipped('veraPDF is not installed; run the suite through .docker');
    }

    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource("pdfa-{$flavour}.pdf"))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    expect(veraPdfVerdict($path, $flavour))->toBe('PASS');

    unlink($path);
})->with(['1b', '2b']);
