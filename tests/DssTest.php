<?php

use Com\Tecnick\Pdf\Sign\Output\Dss;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\RevisionWriter;

it('appends a revision without disturbing what came before', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;

    $reader = app(DocumentReader::class);
    $writer = app(RevisionWriter::class);

    $document = $reader->read($signed);
    $withDss = $writer->appendObjects($signed, $document, [
        $document->size => "{$document->size} 0 obj\n<</Type/Probe>>\nendobj\n",
    ]);

    expect(substr($withDss, 0, strlen($signed)))->toBe($signed);

    // The appended revision must chain correctly, or the file is unreadable.
    $updated = $reader->read($withDss);

    expect($updated->root)->toBe($document->root)
        ->and($updated->startxref)->toBeGreaterThan($document->startxref)
        ->and($updated->xref)->toHaveKey($document->size);
});

it('points the catalog at the emitted store', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;

    $document = app(DocumentReader::class)->read($signed);
    $catalog = app(RevisionWriter::class)->catalogWithDss($signed, $document, 99);

    expect($catalog)->toContain('/DSS 99 0 R')
        ->toStartWith("{$document->root} 0 obj");
});

it('replaces an existing /DSS rather than adding a second one', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;

    $reader = app(DocumentReader::class);
    $writer = app(RevisionWriter::class);

    $document = $reader->read($signed);
    $first = $writer->appendObjects($signed, $document, [
        $document->root => $writer->catalogWithDss($signed, $document, 90),
    ]);

    $updated = $reader->read($first);
    $catalog = $writer->catalogWithDss($first, $updated, 91);

    expect(substr_count($catalog, '/DSS'))->toBe(1)
        ->and($catalog)->toContain('/DSS 91 0 R');
});

it('emits the store keyed by the signature it vouches for', function () {
    $pon = 30;

    $emitted = (new Dss())->emit(
        ['certs' => ['DER-CERT'], 'ocsp' => ['DER-OCSP'], 'crls' => ['DER-CRL']],
        'SIGNATURE-CONTENTS',
        $pon,
    );

    $store = $emitted['objects'][$emitted['object_id']];

    expect($store)->toContain('/Type /DSS')
        ->toContain('/Certs')
        ->toContain('/OCSPs')
        ->toContain('/CRLs')
        // The VRI key is the SHA-1 of the signature contents, uppercased.
        ->toContain(strtoupper(sha1('SIGNATURE-CONTENTS')));
});

it('embeds a document security store at PAdES B-LT', function () {
    // B-LT builds on B-T, so the authority is required regardless of how much
    // revocation material turns out to be available.
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    [$pfxPath, $password] = debugCertificate();

    $plain = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $longTerm = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLT)
        ->sign();

    // A self-signed certificate has no OCSP responder and no CRL distribution
    // point, so only the chain itself is embedded — which is still worth
    // carrying, since a verifier then needs to fetch nothing.
    expect($longTerm->contents)->toContain('/Type /DSS')
        ->toContain('/Certs')
        ->and($plain->contents)->not->toContain('/Type /DSS');

    // The store rides in its own revision, so the signature stays intact.
    $document = app(DocumentReader::class)->read($longTerm->contents);
    expect($document->root)->toBe(14);

    preg_match_all('/\/Prev\s+(\d+)/', $longTerm->contents, $prev);
    expect($prev[1])->toHaveCount(2);
})->group('network');

it('closes with an archive timestamp at PAdES B-LTA', function () {
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    [$pfxPath, $password] = debugCertificate();

    $archived = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    // Signature, store and archive timestamp each ride in their own revision.
    preg_match_all('/\\/Prev\\s+(\\d+)/', $archived->contents, $prev);

    expect($archived->contents)->toContain('/Type /DocTimeStamp')
        ->toContain('/SubFilter /ETSI.RFC3161')
        ->toContain('/Type /DSS')
        ->and($prev[1])->toHaveCount(3);

    // The archive timestamp covers the whole file, unlike the signature which
    // stops at its own revision.
    preg_match_all('/\\/ByteRange\\[0 (\\d+)\\s+(\\d+)\\s+(\\d+)\\s*\\]/', $archived->contents, $ranges, PREG_SET_ORDER);
    /** @var array{0: string, 1: numeric-string, 2: numeric-string, 3: numeric-string} $last */
    $last = end($ranges);

    expect((int) $last[2] + (int) $last[3])->toBe(strlen($archived->contents));

    // And the document still parses after three appended revisions.
    expect(app(DocumentReader::class)->read($archived->contents)->root)->toBe(14);
})->group('network');

it('refuses an archive timestamp without an authority', function () {
    config()->set('a1-pdf-sign.signature.timestamp.url', null);

    [$pfxPath, $password] = debugCertificate();

    A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();
})->throws(ProcessRunTimeException::class);
