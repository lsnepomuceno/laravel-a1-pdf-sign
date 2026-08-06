<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;
use LSNepomuceno\LaravelA1PdfSign\Validation\PdfSignatureExtractor;
use LSNepomuceno\LaravelA1PdfSign\Validation\Pkcs7Reader;

function signedOnce(): string
{
    [$pfxPath, $password] = debugCertificate();

    return A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'Lucas', reason: 'Contract')
        ->sign()
        ->contents;
}

it('verifies a signature cryptographically', function () {
    $report = app(SignatureValidator::class)->validate(signedOnce());

    expect($report)->toBeInstanceOf(SignatureReport::class)
        ->and($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1)
        ->and($report->latest()?->coversWholeDocument)->toBeTrue();
});

it('rejects a document whose bytes were altered after signing', function () {
    $signed = signedOnce();

    // Flip a byte inside the region the signature covers.
    $tampered = substr_replace($signed, 'X', 200, 1);

    $report = app(SignatureValidator::class)->validate($tampered);

    // This is the check 1.x never made: it reported "validated" from the
    // presence of a CN field, which a tampered document still has.
    expect($report->isValid())->toBeFalse()
        ->and($report->isSigned())->toBeTrue();
});

it('reports every signature in a multi-signed document', function () {
    [$pfxPath, $password] = debugCertificate();
    [$pfx, $pass] = DebugCertificate::make();

    $second = A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($second, $pfx);

    $once = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $path = $once->save(A1PdfSign::tempPath(true, '.pdf'));

    $twice = A1PdfSign::newSignature()
        ->certificate($second, $pass)
        ->pdf($path)
        ->sign();

    $report = app(SignatureValidator::class)->validate($twice->contents);

    // 1.x read only the first ByteRange match, so it could not describe a
    // document this package now produces.
    expect($report->count())->toBe(2)
        ->and($report->isValid())->toBeTrue()
        ->and($report->signatures[0]->coversWholeDocument)->toBeFalse()
        ->and($report->signatures[1]->coversWholeDocument)->toBeTrue()
        ->and($report->signers())->toHaveCount(2);

    unlink($path);
});

it('reads the signer identity as structured data', function () {
    $report = app(SignatureValidator::class)->validate(signedOnce());
    $signer = $report->latest()?->signer();

    expect($signer?->commonName)->toBe('Test Certificate')
        ->and($signer?->organizationalUnit)->toBe('LucasNepomuceno')
        ->and($signer?->validTo)->toBeInt()
        ->and($signer?->isExpired())->toBeFalse()
        ->and($signer?->subject)->toHaveKey('commonName');
});

it('raises when the document carries no signature', function () {
    app(SignatureValidator::class)->validate(Files::read(resource('test.pdf')));
})->throws(HasNoSignatureOrInvalidPkcs7Exception::class);

it('extracts the byte ranges and the embedded CMS', function () {
    $extracted = app(PdfSignatureExtractor::class)->extract(signedOnce());

    expect($extracted)->toHaveCount(1)
        ->and($extracted[0]['byteRange'])->toHaveCount(3)
        // The CMS must be trimmed to its declared ASN.1 length, not to the
        // zero padding of the placeholder.
        ->and($extracted[0]['cms'][0])->toBe("\x30")
        ->and(strlen($extracted[0]['cms']))->toBeLessThan(8192);
});

it('ignores a contents block that is not hexadecimal', function () {
    $extracted = app(PdfSignatureExtractor::class)
        ->extract('/ByteRange[0 10 20 30]/Contents <zzzz>' . str_repeat(' ', 40));

    expect($extracted)->toBe([]);
});

it('finds the certificates without parsing openssl text output', function () {
    $extracted = app(PdfSignatureExtractor::class)->extract(signedOnce());
    $certificates = app(Pkcs7Reader::class)->certificates($extracted[0]['cms']);

    expect($certificates)->toHaveCount(1)
        ->and($certificates[0])->toStartWith('-----BEGIN CERTIFICATE-----')
        ->and(openssl_x509_parse($certificates[0]))->toBeArray();
});

it('validates through the artisan command', function () {
    $path = A1PdfSign::tempPath(true, '.pdf');
    file_put_contents($path, signedOnce());

    $this->artisan('pdf:validate-signature', ['pdfPath' => $path])
        ->assertSuccessful()
        ->expectsOutput('Your PDF document is VALID');

    unlink($path);
});

it('accepts an uppercase pdf extension', function () {
    $path = A1PdfSign::tempPath(true, '.PDF');
    file_put_contents($path, signedOnce());

    expect(app(SignatureValidator::class)->validateFile($path)->isValid())->toBeTrue();

    unlink($path);
});

it('rejects a path that is not a pdf', function () {
    app(SignatureValidator::class)->validateFile('/tmp/whatever.txt');
})->throws(InvalidPdfFileException::class);

it('raises when the file does not exist', function () {
    app(SignatureValidator::class)->validateFile('/tmp/missing-' . uniqid() . '.pdf');
})->throws(FileNotFoundException::class);

it('finds no certificates in a blob that holds none', function () {
    $reader = app(Pkcs7Reader::class);

    expect($reader->certificates(''))->toBe([])
        ->and($reader->certificates(str_repeat("\x30\x82\x00\x10", 20)))->toBe([])
        ->and($reader->signers('not der at all'))->toBe([]);
});

it('deduplicates a certificate that appears twice', function () {
    $extracted = app(PdfSignatureExtractor::class)->extract(signedOnce());
    $cms = $extracted[0]['cms'];

    // The same bytes twice must still yield one certificate.
    expect(app(Pkcs7Reader::class)->certificates($cms . $cms))->toHaveCount(1);
});
