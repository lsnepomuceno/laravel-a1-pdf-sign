<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The fake a consuming application uses.
 *
 * An application that signs PDFs has to test the code path that signs them, and
 * doing that for real means a PKCS#12 bundle in its own repository and a real
 * CMS built for every test that merely passes through.
 *
 * The mechanism already existed, since everything resolves through the
 * container. What was missing is knowing what to assert, which is what this
 * encodes.
 *
 * **Every test here signs without a certificate**, which is the whole point.
 */
it('signs nothing and needs no certificate', function () {
    $signing = A1PdfSign::fake();

    A1PdfSign::newSignature()
        ->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())
        ->pdfContents('%PDF-1.4 a contract %%EOF')
        ->sign();

    $signing->assertSigned();
});

it('finds the document it was given', function () {
    $signing = A1PdfSign::fake();

    A1PdfSign::newSignature()->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())->pdfContents('%PDF-1.4 deal 42 %%EOF')->sign();

    $signing->assertSigned('deal 42');
});

it('fails when the document it was asked about was never signed', function () {
    $signing = A1PdfSign::fake();

    A1PdfSign::newSignature()->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())->pdfContents('%PDF-1.4 one %%EOF')->sign();

    expect(fn() => $signing->assertSigned('another'))->toThrow(AssertionFailedError::class);
});

it('counts what was signed', function () {
    $signing = A1PdfSign::fake();

    A1PdfSign::newSignature()->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())->pdfContents('%PDF-1.4 a %%EOF')->sign();
    A1PdfSign::newSignature()->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())->pdfContents('%PDF-1.4 b %%EOF')->sign();

    $signing->assertSignedTimes(2);
});

it('asserts the negative, which is the one that catches a bug', function () {
    $signing = A1PdfSign::fake();

    $signing->assertNothingSigned();

    A1PdfSign::newSignature()->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())->pdfContents('%PDF-1.4 a %%EOF')->sign();

    expect(fn() => $signing->assertNothingSigned())->toThrow(AssertionFailedError::class);
});

it('asserts the profile the application asked for', function () {
    $signing = A1PdfSign::fake();

    A1PdfSign::newSignature()
        ->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())
        ->pdfContents('%PDF-1.4 a %%EOF')
        ->profile(SignatureProfile::PadesBLT)
        ->sign();

    $signing->assertSignedWithProfile(SignatureProfile::PadesBLT);

    expect(fn() => $signing->assertSignedWithProfile(SignatureProfile::Legacy))
        ->toThrow(AssertionFailedError::class);
});

it('asserts a certification, which has consequences a signature does not', function () {
    $signing = A1PdfSign::fake();

    A1PdfSign::newSignature()
        ->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())
        ->pdfContents('%PDF-1.4 a %%EOF')
        ->certify(CertificationLevel::NoChanges)
        ->sign();

    $signing->assertCertified();
    $signing->assertCertified(CertificationLevel::NoChanges);

    expect(fn() => $signing->assertCertified(CertificationLevel::FormFilling))
        ->toThrow(AssertionFailedError::class);
});

it('asserts a visible seal without rendering one', function () {
    $signing = A1PdfSign::fake();

    A1PdfSign::newSignature()
        ->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())
        ->pdfContents('%PDF-1.4 a %%EOF')
        ->seal(placement: new SealPlacement(x: 10, y: 10, width: 100))
        ->sign();

    $signing->assertSealed();
});

it('hands back a document the calling code can use', function () {
    // Application code calls ->contents, ->size() and ->save() on the result,
    // so a null or an empty string would fail somewhere unhelpful.
    A1PdfSign::fake();

    $signed = A1PdfSign::newSignature()->usingCertificate(LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake::certificate())->pdfContents('%PDF-1.4 a %%EOF')->sign();

    expect($signed->contents)->toContain('%PDF-')
        ->and($signed->size())->toBeGreaterThan(0)
        ->and($signed->save(A1PdfSign::tempPath(true, '.pdf')))->toBeFile();
});
