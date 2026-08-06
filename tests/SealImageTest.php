<?php

use Illuminate\Support\Facades\File;
use Intervention\Image\Drivers\Gd\Driver as GDDriver;
use Intervention\Image\ImageManager as IMG;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;
use LSNepomuceno\LaravelA1PdfSign\Sign\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Sign\SignaturePdf;

it('renders a seal image from a certificate', function () {
    $cert = new ManageCert();
    $cert->makeDebugCertificate();

    $image = (new IMG(driver: new GDDriver()))->read(SealImage::fromCert($cert));

    expect($image->toPng()->mediaType())->toBe('image/png')
        ->and($image->width())->toBe(590)
        ->and($image->height())->toBe(295);
});

it('stamps the seal onto a signed pdf', function () {
    $cert = new ManageCert();
    $cert->makeDebugCertificate();

    $imagePath = a1TempDir(true, '.png');
    File::put($imagePath, SealImage::fromCert($cert));

    expect(File::exists($imagePath))->toBeTrue();

    $pdfPath = a1TempDir(true, '.pdf');
    $signed = (new SignaturePdf(resource('test.pdf'), $cert))->setImage($imagePath)->signature();
    File::put($pdfPath, $signed);

    expect(File::exists($pdfPath))->toBeTrue()
        ->and(validatePdfSignature($pdfPath)->isValidated)->toBeTrue();

    File::delete([$imagePath, $pdfPath]);
});
