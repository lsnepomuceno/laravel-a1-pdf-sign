<?php

use Illuminate\Support\Facades\File;
use Intervention\Image\ImageManager as IMG;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;
use LSNepomuceno\LaravelA1PdfSign\Sign\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Sign\SignaturePdf;

test('generate image from cert file', function () {
    $cert = new ManageCert;
    $cert->makeDebugCertificate();

    $image = SealImage::fromCert($cert);

    $interventionImg = new IMG(['driver' => SealImage::IMAGE_DRIVER_GD]);
    $interventionImg = $interventionImg->make($image);

    $this->assertEqualsIgnoringCase('image/png', $interventionImg->mime());
    expect($interventionImg->width())->toEqual(590);
    expect($interventionImg->height())->toEqual(295);
});

test('insert seal image on pdf file', function () {
    $cert = new ManageCert;
    $cert->makeDebugCertificate();

    $image     = SealImage::fromCert($cert);
    $imagePath = a1TempDir(true, '.png');
    File::put($imagePath, $image);
    expect(File::exists($imagePath))->toBeTrue();

    $pdfPath = a1TempDir(true, '.pdf');
    try {
        $pdf      = new SignaturePdf(__DIR__ . '/Resources/test.pdf', $cert);
        $resource = $pdf->setImage($imagePath)
            ->signature();
        File::put($pdfPath, $resource);
    } catch (Throwable $e) {
        throw new $e;
    }

    expect(File::exists($pdfPath))->toBeTrue();

    $validation = validatePdfSignature($pdfPath);
    expect($validation->isValidated)->toBeTrue();

    File::delete([$imagePath, $pdfPath]);
});
