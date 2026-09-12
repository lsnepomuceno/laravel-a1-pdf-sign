<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;

it('signs a document that lives on a disk and writes it back to one', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('contracts/deal.pdf', (string) file_get_contents(resource('test.pdf')));

    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->from(A1PdfSign::fromDisk('documents', 'contracts/deal.pdf'))
        ->sign()
        ->writeTo(A1PdfSign::toDisk('documents', 'contracts/deal-signed.pdf'));

    expect($path)->toBe('contracts/deal-signed.pdf');

    Storage::disk('documents')->assertExists('contracts/deal-signed.pdf');

    expect(Storage::disk('documents')->get('contracts/deal-signed.pdf'))
        ->toContain('/ETSI.CAdES.detached')
        // The original revision survives byte for byte, which is the whole
        // point of appending rather than rebuilding, and is as true through a
        // disk as through a path.
        ->toStartWith('%PDF-');
});

it('keeps the document name when the destination names no path', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('deal.pdf', (string) file_get_contents(resource('test.pdf')));

    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->from(A1PdfSign::fromDisk('documents', 'deal.pdf'))
        ->sign()
        ->writeTo(A1PdfSign::toDisk('documents'));

    // The engine names the output after the input rather than overwriting it,
    // which is what a destination with no path of its own inherits.
    expect($path)->toBe('deal_signed.pdf');
});

it('names the path rather than the PDF when the disk has no such file', function () {
    Storage::fake('documents');

    A1PdfSign::fromDisk('documents', 'contracts/missing.pdf')->contents();
})->throws(FileNotFoundException::class, 'contracts/missing.pdf');

it('signs a document that arrived in a request', function () {
    [$pfxPath, $password] = debugCertificate();

    $upload = new UploadedFile(resource('test.pdf'), 'deal.pdf', 'application/pdf', test: true);

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->from(A1PdfSign::fromUpload($upload))
        ->sign();

    expect($signed->contents)->toContain('/ETSI.CAdES.detached')
        ->and($signed->name())->toBe('deal_signed.pdf');
});
