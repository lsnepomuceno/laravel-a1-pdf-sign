<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Validation\SecurityStoreReader;

function sample(string $name): string
{
    return __DIR__ . '/../samples/' . $name;
}

it('reads the store a B-LT document carries', function () {
    $report = A1PdfSign::validate(sample('pades-b-lt.pdf'));

    expect($report->securityStore)->not->toBeNull()
        ->and($report->securityStore?->certificates)->toBeGreaterThan(0)
        ->and($report->securityStore?->isEmpty())->toBeFalse();
});

it('ties the store to the signature it was written for', function () {
    // /VRI names a signature by the SHA-1 of its /Contents, so this is what
    // tells "carries material" apart from "carries material for this one".
    $report = A1PdfSign::validate(sample('pades-b-lt.pdf'));

    expect($report->securityStore?->signatureKeys)->not->toBeEmpty()
        ->and($report->securityStore?->covers($report->signatures[0]))->toBeTrue()
        ->and($report->hasLongTermMaterial())->toBeTrue();
});

it('reports no store for the profiles that carry none', function () {
    // An absent store and an empty one are different answers, so B-B returns
    // null rather than a store of zeroes.
    foreach (['legacy.pdf', 'pades-b-b.pdf'] as $name) {
        expect(A1PdfSign::validate(sample($name))->securityStore)->toBeNull();
        expect(A1PdfSign::validate(sample($name))->hasLongTermMaterial())->toBeFalse();
    }
});

it('reads the last store, not the first', function () {
    // B-LTA appends an archive timestamp after the store, and a document signed
    // again would carry a second store superseding the first.
    $report = A1PdfSign::validate(sample('pades-b-lta.pdf'));

    expect($report->securityStore)->not->toBeNull()
        ->and($report->securityStore?->certificates)->toBeGreaterThan(0);
});

it('reads a nested VRI without stopping at the first closing marker', function () {
    // /VRI nests, so a reader that took the first ">>" would cut the store in
    // half and report no certificates at all.
    $store = (new SecurityStoreReader())->read(
        "junk << /Type /DSS /VRI << /" . str_repeat('A', 40) . " 9 0 R >> /Certs [ 1 0 R 2 0 R ] /OCSPs [ 3 0 R ] >> trailing",
    );

    expect($store?->certificates)->toBe(2)
        ->and($store?->ocspResponses)->toBe(1)
        ->and($store?->crls)->toBe(0)
        ->and($store?->signatureKeys)->toBe([str_repeat('A', 40)]);
});

it('finds no store in a document that has none', function () {
    expect((new SecurityStoreReader())->read(Files::read(resource('test.pdf'))))->toBeNull();
});

it('reads a store nested three levels deep', function () {
    // The delimiter counting has to survive more than the one level /VRI needs,
    // because nothing stops a producer from nesting further.
    $store = (new SecurityStoreReader())->read(
        'x << /Type /DSS /VRI << /' . str_repeat('B', 40) . ' << /Inner << /Deeper 1 0 R >> >> >> /Certs [ 4 0 R ] >> after',
    );

    expect($store?->certificates)->toBe(1)
        ->and($store?->signatureKeys)->toBe([str_repeat('B', 40)]);
});

it('stops at the end of the store, not at the end of the file', function () {
    // Whatever follows the dictionary must not be read into it: a /Certs array
    // belonging to something else would inflate the count.
    $store = (new SecurityStoreReader())->read(
        'a << /Type /DSS /Certs [ 1 0 R ] >> then << /Certs [ 2 0 R 3 0 R 4 0 R ] >>',
    );

    expect($store?->certificates)->toBe(1);
});

it('survives a store the file cuts off', function () {
    // A truncated document should answer with what it has rather than loop or
    // throw: the reader falls through to the remainder.
    $store = (new SecurityStoreReader())->read('<< /Type /DSS /Certs [ 1 0 R 2 0 R ]');

    expect($store?->certificates)->toBe(2);
});

it('counts an empty or malformed array as nothing', function () {
    $reader = new SecurityStoreReader();

    expect($reader->read('<< /Type /DSS /Certs [] >>')?->certificates)->toBe(0)
        ->and($reader->read('<< /Type /DSS /Certs [ not a reference ] >>')?->certificates)->toBe(0)
        ->and($reader->read('<< /Type /DSS >>')?->certificates)->toBe(0);
});

it('normalises the VRI keys it reports', function () {
    // /VRI keys are hex and a producer may write them in either case, while
    // covers() compares against an uppercase sha1.
    $store = (new SecurityStoreReader())->read(
        '<< /Type /DSS /VRI << /' . str_repeat('a', 40) . ' 9 0 R >> >>',
    );

    expect($store?->signatureKeys)->toBe([str_repeat('A', 40)]);
});

it('ignores VRI entries that are not signature keys', function () {
    $store = (new SecurityStoreReader())->read(
        '<< /Type /DSS /VRI << /TooShort 1 0 R /' . str_repeat('C', 40) . ' 2 0 R >> >>',
    );

    expect($store?->signatureKeys)->toBe([str_repeat('C', 40)]);
});

it('reads the newest store when a document carries two', function () {
    // A document signed twice carries a store per revision, and the later one
    // supersedes the earlier (docs/spec/invariants.md).
    $store = (new SecurityStoreReader())->read(
        '<< /Type /DSS /Certs [ 1 0 R ] >> ... << /Type /DSS /Certs [ 2 0 R 3 0 R ] /CRLs [ 4 0 R ] >>',
    );

    expect($store?->certificates)->toBe(2)
        ->and($store?->crls)->toBe(1);
});
