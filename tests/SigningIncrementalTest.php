<?php

use LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\IncrementalSigner;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;

function testCertificate(): LSNepomuceno\LaravelA1PdfSign\Data\Certificate
{
    [$pfx, $password] = DebugCertificate::make();

    return app(NativeCertificateReader::class)->read($pfx, $password);
}

it('leaves the original bytes untouched', function () {
    $original = file_get_contents(resource('test.pdf'));

    $signed = app(IncrementalSigner::class)
        ->sign($original, testCertificate(), new SignatureInfo(name: 'Signer'));

    expect(substr($signed->contents, 0, strlen($original)))->toBe($original)
        ->and($signed->size())->toBeGreaterThan(strlen($original));
});

it('signs the same document three times without invalidating earlier signatures', function () {
    $signer = app(IncrementalSigner::class);
    $certificate = testCertificate();
    $pdf = file_get_contents(resource('test.pdf'));

    for ($round = 1; $round <= 3; $round++) {
        $pdf = $signer->sign($pdf, $certificate, new SignatureInfo(name: "Signer {$round}"))->contents;
    }

    $byteRanges = preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $matches, PREG_SET_ORDER);

    expect($byteRanges)->toBe(3)
        ->and(preg_match_all('/\/Type\s*\/Sig[^n]/', $pdf))->toBe(3)
        // For a given signature, the second span ends where the file ended
        // when it was written. The first therefore stops short of the current
        // length while the last reaches it: that is precisely what leaves the
        // earlier signatures valid.
        ->and((int) $matches[0][2] + (int) $matches[0][3])->toBeLessThan(strlen($pdf))
        ->and((int) $matches[1][2] + (int) $matches[1][3])->toBeLessThan(strlen($pdf))
        ->and((int) $matches[2][2] + (int) $matches[2][3])->toBe(strlen($pdf));

    // Every revision chains to the one before it.
    preg_match_all('/\/Prev\s+(\d+)/', $pdf, $prev);
    expect($prev[1])->toHaveCount(3);
});

it('gives each signature its own field name', function () {
    $signer = app(IncrementalSigner::class);
    $certificate = testCertificate();

    $pdf = file_get_contents(resource('test.pdf'));
    $pdf = $signer->sign($pdf, $certificate, new SignatureInfo())->contents;
    $pdf = $signer->sign($pdf, $certificate, new SignatureInfo())->contents;

    preg_match_all('/\/T \(([^)]+)\)/', $pdf, $names);

    expect($names[1])->toBe(['Signature1', 'Signature2']);
});

it('writes the signature metadata into the document', function () {
    $signed = app(IncrementalSigner::class)->sign(
        file_get_contents(resource('test.pdf')),
        testCertificate(),
        new SignatureInfo(name: 'Lucas', location: 'Brazil', reason: 'Contract'),
    );

    expect($signed->contents)->toContain('/Name (Lucas)')
        ->toContain('/Location (Brazil)')
        ->toContain('/Reason (Contract)');
});

it('rejects a file that is not a PDF', function () {
    app(IncrementalSigner::class)->sign('not a pdf', testCertificate(), new SignatureInfo());
})->throws(InvalidPdfFileException::class);

it('reads the last byte range, not the first', function () {
    $calculator = new ByteRangeCalculator();

    $pdf = '/ByteRange[0 10 20 30]' . str_repeat('x', 50) . '/ByteRange[0 40 50 60]';

    expect($calculator->readLast($pdf))->toBe([40, 50, 60]);
});

it('reads the cross-reference chain of an unsigned document', function () {
    $document = app(DocumentReader::class)->read(file_get_contents(resource('test.pdf')));

    expect($document->root)->toBe(14)
        ->and($document->size)->toBe(19)
        ->and($document->nextObjectNumber())->toBe(19)
        ->and($document->xref)->not->toBeEmpty();
});

it('signs through the fluent builder', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'Lucas', reason: 'Contract')
        ->sign();

    expect($signed)->toBeInstanceOf(SignedPdf::class)
        ->and($signed->fileName)->toBe('test_signed.pdf')
        ->and($signed->contents)->toStartWith('%PDF-');
});

it('lets the caller choose the transport after signing', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $path = $signed->save(A1PdfSign::tempPath(true, '.pdf'));

    expect($signed->contents())->toBe((string) $signed)
        ->and(file_get_contents($path))->toBe($signed->contents)
        ->and($signed->download()->getStatusCode())->toBe(200)
        ->and($signed->toResponse()->headers->get('Content-Type'))->toBe('application/pdf');

    unlink($path);
});
