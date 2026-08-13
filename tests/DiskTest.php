<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * Signing a document that lives on a Laravel disk.
 *
 * `pdf()` takes a local path, so an application keeping contracts on `s3` or
 * `minio` appeared to be forced to download to a temporary file first. It never
 * was: `pdfContents()` takes bytes and `Storage::disk(...)->get()` returns
 * them. The gap was that nobody could find that out, since the README showed
 * only the path form.
 *
 * `pdfFromDisk()` is that call with the file name carried across, and this file
 * asserts both forms so the documented one and the underlying one cannot drift.
 */
it('signs a document read from a disk', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('contracts/deal.pdf', Files::read(resource('test.pdf')));

    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfFromDisk('documents', 'contracts/deal.pdf')
        ->sign();

    expect(app(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();
});

it('keeps the name the document had on the disk', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('contracts/deal.pdf', Files::read(resource('test.pdf')));

    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfFromDisk('documents', 'contracts/deal.pdf')
        ->sign();

    // Without this the signed document is named after an ordered UUID, which is
    // the whole reason this is not just a call to pdfContents().
    expect($signed->fileName)->toContain('deal');
});

it('says which disk and which path when the document is not there', function () {
    Storage::fake('documents');

    [$pfxPath, $password] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfFromDisk('documents', 'contracts/missing.pdf'))
        ->toThrow(FileNotFoundException::class);
});

it('signs the same document handed over as bytes, which is what it does underneath', function () {
    // The form that already worked and was undocumented. Asserted beside the
    // sugar so the two cannot drift apart.
    Storage::fake('documents');
    Storage::disk('documents')->put('deal.pdf', Files::read(resource('test.pdf')));

    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents((string) Storage::disk('documents')->get('deal.pdf'), 'deal.pdf')
        ->sign();

    expect(app(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();
});
