<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificationException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\ArchiveExtender;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * Extending the archive timestamp chain, ETSI EN 319 142-1.
 *
 * B-LTA is not a state a document stays in: the authority's certificate and the
 * digest algorithm behind the timestamp both age, and the answer is a chain of
 * timestamps rather than one. The package could produce the first link and not
 * the second.
 *
 * The guards run offline; the extension itself needs a timestamp authority and
 * is in the network group, like every other test that reaches one.
 *
 * See docs/decisions/0022-the-archive-timestamp-is-a-chain.md.
 */
it('refuses to archive a document nobody signed', function () {
    // Legal and pointless: it attests bytes nobody vouched for, and returns a
    // file that looks archived while proving nothing about a signer.
    expect(fn() => app(ArchiveExtender::class)->extend(Files::read(resource('test.pdf')), 'test.pdf'))
        ->toThrow(HasNoSignatureOrInvalidPkcs7Exception::class);
});

it('refuses to archive a document certified as no-changes', function () {
    // An archive timestamp is a further revision, which is exactly what /P 1
    // forbids (docs/decisions/0012-certification-signatures.md).
    [$pfxPath, $password] = debugCertificate();

    $certified = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify('no-changes')
        ->sign();

    expect(fn() => app(ArchiveExtender::class)->extend($certified->contents))
        ->toThrow(CertificationException::class, 'forbids the further revision an archive timestamp would append');
});

it('reports whether a document is already archived', function () {
    // Extending one that has none still makes it B-LTA from that point on, so
    // this is reported rather than enforced.
    $extender = app(ArchiveExtender::class);

    expect($extender->isArchived(Files::read(sample('pades-b-lta.pdf'))))->toBeTrue()
        ->and($extender->isArchived(Files::read(sample('pades-b-t.pdf'))))->toBeFalse();
});

it('names the timestamp field from the form rather than from a byte scan', function () {
    // The index used to come from counting "/FT /Sig" in the raw bytes, which
    // undercounts a document whose fields are packed into an object stream, and
    // two fields sharing a name is a form readers disagree about.
    $pdf = Files::read(sample('pades-b-lta.pdf'));

    preg_match_all('/\/T \(([^)]+)\)/', $pdf, $names);

    expect($names[1])->toBe(['Signature1', 'Timestamp2']);
});

it('extends the archive timestamp chain', function () {
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    $original = Files::read(sample('pades-b-lta.pdf'));

    $extended = app(ArchiveExtender::class)->extend($original, 'archive.pdf');

    // The invariant the whole signer is built around: the previous links stay
    // byte for byte, which is what lets the new timestamp attest them.
    expect(substr($extended->contents, 0, strlen($original)))->toBe($original)
        ->and($extended->fileName)->toBe('archive.pdf');

    $report = app(SignatureValidator::class)->validate($extended->contents);

    // One signature and two archive timestamps, all three verifying.
    expect($report->timestamps())->toHaveCount(2)
        ->and($report->isValid())->toBeTrue()
        ->and($report->latest()?->isTimestamp)->toBeTrue()
        ->and($report->latest()?->coversWholeDocument)->toBeTrue();

    $signature = $report->signatures[0];

    // The signature underneath is untouched, and still reads as B-LTA.
    expect($signature->verified)->toBeTrue()
        ->and($signature->profile)->toBe(SignatureProfile::PadesBLTA)
        ->and($signature->timestampVerified)->toBeTrue();
})->group('network');

it('archives a B-T document, which makes it archived from here on', function () {
    // Not the usual case, and not refused: the chain has to start somewhere.
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    $extended = app(ArchiveExtender::class)->extend(Files::read(sample('pades-b-t.pdf')));

    $report = app(SignatureValidator::class)->validate($extended->contents);

    expect($report->timestamps())->toHaveCount(1)
        ->and($report->isValid())->toBeTrue();
})->group('network');

it('extends through the facade', function () {
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    $path = A1PdfSign::tempPath(true, '.pdf');
    file_put_contents($path, Files::read(sample('pades-b-lta.pdf')));

    $extended = A1PdfSign::extendArchive($path);

    expect($extended->fileName)->toBe(basename($path))
        ->and(app(SignatureValidator::class)->validate($extended->contents)->timestamps())
        ->toHaveCount(2);

    unlink($path);
})->group('network');
