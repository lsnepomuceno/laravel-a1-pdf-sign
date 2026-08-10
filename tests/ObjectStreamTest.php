<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\ObjectStreamReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * Objects packed into an object stream, ISO 32000-1 §7.5.7.
 *
 * The fixture packs its catalog, page tree and page into one stream, which is
 * what Word and "print to PDF" in Chrome do: they are dictionaries, and a
 * dictionary is exactly what a producer packs. See
 * docs/decisions/0015-object-streams.md.
 */
function packedDocument(): string
{
    return Files::read(resource('object-stream.pdf'));
}

it('locates the objects an object stream packs', function () {
    $document = app(DocumentReader::class)->read(packedDocument());

    // The two maps are disjoint: an object is at an offset or inside a stream.
    expect($document->compressed)->toHaveKeys([1, 2, 3])
        ->and($document->xref)->toHaveKeys([4, 5, 6])
        ->and($document->xref)->not->toHaveKey(1)
        ->and($document->isCompressed(1))->toBeTrue()
        ->and($document->isCompressed(4))->toBeFalse()
        ->and($document->objectNumbers())->toBe([1, 2, 3, 4, 5, 6]);
});

it('reads a packed object as if it were an ordinary one', function () {
    $reader = app(DocumentReader::class);
    $pdf = packedDocument();

    expect($reader->rawObject($pdf, $reader->read($pdf), 1))
        ->toBe('<</Type/Catalog/Pages 2 0 R>>');
});

it('finds a page that is packed rather than at an offset', function () {
    // A page dictionary is a prime candidate for packing, so searching only the
    // offsets finds no page in exactly the documents this is about.
    $reader = app(DocumentReader::class);
    $pdf = packedDocument();

    expect($reader->findFirstPage($pdf, $reader->read($pdf)))->toBe(3);
});

it('signs a document whose catalog is packed', function () {
    // This raised "object 1 is missing from the cross-reference table" until
    // packed objects could be read: signing rewrites the catalog to register
    // the field, so a catalog it cannot read is a document it cannot sign.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('object-stream.pdf'))
        ->sign();

    $original = packedDocument();

    expect(substr($signed->contents, 0, strlen($original)))->toBe($original)
        // Written back at the top level rather than unpacked in place: the
        // packed copy stays in the file as history.
        ->and($signed->contents)->toContain("1 0 obj\n<</Type/Catalog");

    $report = app(SignatureValidator::class)->validate($signed->contents);

    expect($report->isValid())->toBeTrue()
        ->and($report->signatures)->toHaveCount(1);
});

it('reads the top-level copy after a revision supersedes the packed one', function () {
    // The trap: merging the two maps additively would leave the second
    // signature reading the stale packed catalog, without its /AcroForm.
    [$pfxPath, $password] = debugCertificate();

    $once = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('object-stream.pdf'))
        ->sign();

    $reader = app(DocumentReader::class);
    $document = $reader->read($once->contents);

    expect($document->isCompressed(1))->toBeFalse()
        ->and($reader->rawObject($once->contents, $document, 1))->toContain('/AcroForm');

    $twice = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($once->contents, 'packed.pdf')
        ->info(name: 'Second signer')
        ->sign();

    $report = app(SignatureValidator::class)->validate($twice->contents);

    expect($report->signatures)->toHaveCount(2)
        ->and($report->isValid())->toBeTrue();
});

it('reports an object stream it cannot decode rather than guessing at a body', function () {
    expect(new ObjectStreamReader()->object('%PDF-1.5\n1 0 obj\n<</Type/ObjStm/N 1/First 4>>\nstream\n', 9, 1))
        ->toBeNull();
});

it('ignores an object the stream does not carry', function () {
    $reader = app(DocumentReader::class);
    $pdf = packedDocument();
    $document = $reader->read($pdf);

    expect(new ObjectStreamReader()->object($pdf, $document->xref[5], 99))->toBeNull();
});

it('refuses an object whose stream is not one', function () {
    // A /Perms-style pointer at the wrong object type must not be read as a
    // packed body.
    $reader = app(DocumentReader::class);
    $pdf = packedDocument();
    $document = $reader->read($pdf);

    expect(new ObjectStreamReader()->object($pdf, $document->xref[4], 1))->toBeNull();
});
