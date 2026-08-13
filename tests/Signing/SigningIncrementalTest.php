<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\XrefSubsections;
use LSNepomuceno\LaravelA1PdfSign\Signing\IncrementalSigner;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

it('leaves the original bytes untouched', function () {
    $original = Files::read(resource('test.pdf'));

    $signed = app(IncrementalSigner::class)
        ->sign($original, testCertificate(), new SignatureInfo(name: 'Signer'));

    expect(substr($signed->contents, 0, strlen($original)))->toBe($original)
        ->and($signed->size())->toBeGreaterThan(strlen($original));
});

it('signs the same document three times without invalidating earlier signatures', function () {
    $signer = app(IncrementalSigner::class);
    $certificate = testCertificate();
    $pdf = Files::read(resource('test.pdf'));

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

    $pdf = Files::read(resource('test.pdf'));
    $pdf = $signer->sign($pdf, $certificate, new SignatureInfo())->contents;
    $pdf = $signer->sign($pdf, $certificate, new SignatureInfo())->contents;

    preg_match_all('/\/T \(([^)]+)\)/', $pdf, $names);

    expect($names[1])->toBe(['Signature1', 'Signature2']);
});

it('writes the signature metadata into the document', function () {
    // A variable rather than the call inline: the signer takes the document by
    // reference so it can release it while hashing, and PHP does not pass an
    // expression by reference (docs/decisions/0034-signing-owns-the-document.md).
    $contents = Files::read(resource('test.pdf'));

    $signed = app(IncrementalSigner::class)->sign(
        $contents,
        testCertificate(),
        new SignatureInfo(name: 'Lucas', location: 'Brazil', reason: 'Contract'),
    );

    expect($signed->contents)->toContain('/Name (Lucas)')
        ->toContain('/Location (Brazil)')
        ->toContain('/Reason (Contract)');
});

it('rejects a file that is not a PDF', function () {
    $contents = 'not a pdf';

    app(IncrementalSigner::class)->sign($contents, testCertificate(), new SignatureInfo());
})->throws(InvalidPdfFileException::class);

it('reads the last byte range, not the first', function () {
    $calculator = new ByteRangeCalculator();

    $pdf = '/ByteRange[0 10 20 30]' . str_repeat('x', 50) . '/ByteRange[0 40 50 60]';

    expect($calculator->readLast($pdf))->toBe([40, 50, 60]);
});

it('reads the cross-reference chain of an unsigned document', function () {
    $document = app(DocumentReader::class)->read(Files::read(resource('test.pdf')));

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
        ->and(Files::read($path))->toBe($signed->contents)
        ->and($signed->download()->getStatusCode())->toBe(200)
        ->and($signed->toResponse()->headers->get('Content-Type'))->toBe('application/pdf');

    unlink($path);
});

it('reads a cross-reference stream', function () {
    // This asserted a refusal until reading landed, which is what the boundary
    // test was for (docs/decisions/0009-cross-reference-streams.md). PDF 1.5 is
    // from 2003 and this is the form most modern generators emit.
    $document = app(DocumentReader::class)->read(Files::read(resource('xref-stream.pdf')));

    expect($document->root)->toBe(1)
        ->and($document->size)->toBe(6)
        ->and($document->xref)->toHaveKeys([1, 2, 3, 4, 5]);
});

it('refuses an encrypted document rather than corrupting it', function () {
    // The cross-reference table is not encrypted, so reading gets far enough to
    // look successful while everything around it is unreadable. Silence here
    // produced a file whose new objects no reader could decrypt.
    expect(fn() => app(DocumentReader::class)->read(Files::read(resource('encrypted.pdf'))))
        ->toThrow(InvalidPdfFileException::class, 'the document is encrypted');
});

it('names the structural fault instead of blaming the file extension', function () {
    // Fifteen of the sixteen call sites raise this for reasons that have
    // nothing to do with a filename, and every one of them used to say
    // "Invalid file extension" (docs/decisions/0008-exceptions-name-the-real-fault.md).
    try {
        app(DocumentReader::class)->read('not a pdf at all');
        $message = 'no exception';
    } catch (InvalidPdfFileException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain('no startxref pointer found')
        ->not->toContain('Invalid file extension');
});

it('keeps the extension message for the one case that meant it', function () {
    expect(InvalidPdfFileException::extension('/tmp/contract.docx')->getMessage())
        ->toBe('Invalid file extension, accept only ".pdf" extension files. Current file: /tmp/contract.docx.');
});

it('signs a cross-reference stream document by appending another stream', function () {
    // This asserted a refusal until writing landed. Appending a classic table
    // to a document whose latest section is a stream produced a file poppler
    // reported as carrying no signatures at all, so the shape of what gets
    // appended has to follow the shape already there
    // (docs/decisions/0009-cross-reference-streams.md).
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('xref-stream.pdf'))
        ->sign();

    // The original bytes survive, which is the invariant the whole signer is
    // built around (docs/spec/invariants.md).
    $original = Files::read(resource('xref-stream.pdf'));

    expect(substr($signed->contents, 0, strlen($original)))->toBe($original)
        ->and($signed->contents)->toContain('/Type/XRef')
        ->and($signed->contents)->not->toContain("\ntrailer\n");

    // The appended section has to be readable in its own right, since the next
    // revision chains onto it.
    $document = app(DocumentReader::class)->read($signed->contents);

    expect($document->usesXrefStream)->toBeTrue()
        ->and($document->root)->toBe(1);

    $report = app(SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->signatures)->toHaveCount(1);
});

it('keeps the earlier signature when a stream document is signed twice', function () {
    // The trap this guards is the cross-reference stream indexing itself: a
    // second revision can only find the first one's objects through it.
    [$pfxPath, $password] = debugCertificate();

    $once = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('xref-stream.pdf'))
        ->sign();

    $twice = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($once->contents, 'xref-stream.pdf')
        ->sign();

    expect(substr($twice->contents, 0, strlen($once->contents)))->toBe($once->contents);

    $report = app(SignatureValidator::class)->validate($twice->contents);

    expect($report->signatures)->toHaveCount(2)
        ->and($report->isValid())->toBeTrue();
});

it('writes the signature onto the page rather than onto the catalog', function () {
    // findFirstPage scanned a fixed window from each object's offset, which in
    // a compact document reaches the objects that follow. The catalog was
    // reported as the first page because a /Type/Page two objects later fell
    // inside that window, and the revision then wrote /AcroForm and /Annots
    // both onto object 1, the second silently dropping the first.
    $reader = app(DocumentReader::class);
    $pdf = Files::read(resource('xref-stream.pdf'));

    expect($reader->findFirstPage($pdf, $reader->read($pdf)))->toBe(3);
});

it('groups objects into runs of consecutive numbers', function () {
    // /Index and the classic "first count" subsection header both describe
    // runs. A revision's numbers are never one unbroken run, because it
    // touches the catalog and a page low in the file and writes its new
    // objects high in it, so declaring them as one would misplace every entry
    // past the gap. The input is deliberately unordered: the offsets arrive
    // keyed by object number in the order they were written, not sorted.
    expect(new XrefSubsections()->of([7 => 300, 1 => 100, 8 => 400, 2 => 200]))
        ->toBe([[1 => 100, 2 => 200], [7 => 300, 8 => 400]]);
});
