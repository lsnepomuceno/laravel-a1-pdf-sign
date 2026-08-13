<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Validation\Asn1Reader;
use LSNepomuceno\LaravelA1PdfSign\Validation\SignatureVerifier;
use LSNepomuceno\LaravelA1PdfSign\Validation\TimestampTokenReader;

/**
 * The RFC 3161 token a B-T signature carries, and the profile it puts it at.
 *
 * The package embedded this token from 2.0 and never read it back, so a
 * document at pades-b-t reported valid without anyone checking the one thing
 * that profile adds over B-B. See
 * docs/decisions/0019-validation-reads-what-it-writes.md.
 *
 * These run against the committed samples because those carry tokens from a
 * real authority. A fixture generated offline would only prove the reader
 * agrees with the writer.
 */
function reportFor(string $name): LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport
{
    return app(SignatureValidator::class)->validate(Files::read(sample($name)));
}

it('verifies the timestamp a B-T signature carries', function () {
    $signature = reportFor('pades-b-t.pdf')->latest();

    expect($signature?->timestampVerified)->toBeTrue()
        ->and($signature?->hasTimestamp())->toBeTrue()
        // freetsa.org's own clock, at the moment the sample was produced.
        ->and($signature?->stampedAt)->toBeGreaterThan(0)
        ->and($signature?->attestedAt())->toBe($signature?->stampedAt);
});

it('reports no timestamp at B-B, which is an absence rather than a failure', function () {
    // Null and false are different answers. Collapsing them would report every
    // baseline signature as carrying a broken token.
    $signature = reportFor('pades-b-b.pdf')->latest();

    expect($signature?->timestampVerified)->toBeNull()
        ->and($signature?->hasTimestamp())->toBeFalse()
        ->and($signature?->stampedAt)->toBeNull()
        ->and($signature?->attestedAt())->toBeNull();
});

it('reads the profile each signature actually reaches', function (string $file, SignatureProfile $profile) {
    expect(reportFor($file)->latest()?->profile)->toBe($profile);
})->with([
    'legacy' => ['legacy.pdf', SignatureProfile::Legacy],
    'b-b' => ['pades-b-b.pdf', SignatureProfile::PadesBB],
    'b-t' => ['pades-b-t.pdf', SignatureProfile::PadesBT],
    'b-lt' => ['pades-b-lt.pdf', SignatureProfile::PadesBLT],
]);

it('classifies the archive timestamp as a timestamp rather than a profile', function () {
    // The last entry of a B-LTA is a DocTimeStamp: a timestamp over the file,
    // not a signature at a level.
    $report = reportFor('pades-b-lta.pdf');
    $entries = $report->signatures;

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->profile)->toBe(SignatureProfile::PadesBLTA)
        ->and($entries[1]->isTimestamp)->toBeTrue()
        ->and($entries[1]->subFilter)->toBe('ETSI.RFC3161')
        ->and($entries[1]->profile)->toBeNull()
        ->and($report->isValid())->toBeTrue();
});

it('reports the sub-filter as written, beside the profile it read', function () {
    // The two answer different questions: one is what the file claims, the
    // other is what it carries. A caller comparing them can see a document that
    // says CAdES while holding nothing a CAdES signature needs.
    expect(reportFor('pades-b-t.pdf')->latest()?->subFilter)->toBe('ETSI.CAdES.detached')
        ->and(reportFor('legacy.pdf')->latest()?->subFilter)->toBe('adbe.pkcs7.detached');
});

it('refuses a token that stamps something else', function () {
    // The imprint is the whole point. A token lifted from another document has
    // a CMS that verifies perfectly and stamps bytes that are not these.
    $signature = reportFor('pades-b-t.pdf')->latest();
    $token = new TimestampTokenReader()->read($signature->rawContents ?? '');

    expect($token)->not->toBeNull();

    // Narrowed above; PHPStan cannot follow Pest's expectation chain.
    assert($token !== null);

    $verifier = app(SignatureVerifier::class);

    expect($verifier->verifiedTimestampInfo($token['token'], 'not what was stamped'))->toBeNull()
        ->and($verifier->verifiedTimestampInfo($token['token'], $token['stamped']))->not->toBeNull();
});

it('finds no token in a CMS that carries none', function () {
    $signature = reportFor('pades-b-b.pdf')->latest();
    $cms = $signature->rawContents ?? '';

    expect($cms)->not->toBe('')
        ->and(new TimestampTokenReader()->read($cms))->toBeNull();
});

it('classifies from what the document carries, not from what it claims', function () {
    // Straight at the enum, since the combinations a document can present are
    // wider than the samples committed here.
    expect(SignatureProfile::classify('adbe.pkcs7.detached', false, false, false))
        ->toBe(SignatureProfile::Legacy)
        // A legacy sub-filter stays legacy however much material sits around it.
        ->and(SignatureProfile::classify('adbe.pkcs7.detached', true, true, true))
        ->toBe(SignatureProfile::Legacy)
        ->and(SignatureProfile::classify('ETSI.CAdES.detached', false, false, false))
        ->toBe(SignatureProfile::PadesBB)
        // A store with no verified timestamp is not B-LT: the levels are
        // cumulative, and B-LT is B-T plus a store.
        ->and(SignatureProfile::classify('ETSI.CAdES.detached', false, true, true))
        ->toBe(SignatureProfile::PadesBB)
        ->and(SignatureProfile::classify('ETSI.CAdES.detached', true, false, false))
        ->toBe(SignatureProfile::PadesBT)
        ->and(SignatureProfile::classify('ETSI.CAdES.detached', true, true, false))
        ->toBe(SignatureProfile::PadesBLT)
        ->and(SignatureProfile::classify('ETSI.CAdES.detached', true, true, true))
        ->toBe(SignatureProfile::PadesBLTA)
        ->and(SignatureProfile::classify('ETSI.RFC3161', true, true, true))->toBeNull()
        ->and(SignatureProfile::classify(null, false, false, false))->toBeNull();
});

it('walks a DER structure by its declared lengths', function () {
    $reader = new Asn1Reader();

    // SEQUENCE { INTEGER 1, OCTET STRING "hi" }
    $der = "\x30\x07\x02\x01\x01\x04\x02hi";

    $root = $reader->at($der);

    expect($root?->tag)->toBe(0x30)
        ->and($root?->length)->toBe(7)
        ->and($root?->isConstructed())->toBeTrue();

    $children = $reader->children($der);

    expect($children)->toHaveCount(2)
        ->and($children[0]->tag)->toBe(0x02)
        ->and($children[1]->content($der))->toBe('hi')
        ->and($children[1]->isConstructed())->toBeFalse();
});

it('refuses a structure whose length runs past the buffer', function () {
    // Reading it anyway would hand a caller a node describing bytes that are
    // not there, and substr would quietly return a short string.
    $reader = new Asn1Reader();

    expect($reader->at("\x30\x7f\x01"))->toBeNull()
        // The indefinite form, which DER forbids outright.
        ->and($reader->at("\x30\x80\x01\x02"))->toBeNull()
        // A multi-byte tag, which nothing in CMS uses.
        ->and($reader->at("\x1f\x01\x00"))->toBeNull()
        ->and($reader->at('', 0))->toBeNull();
});

it('abandons a parent whose children do not fit it', function () {
    // Half a walk is worse than none: a caller indexes into the list and gets
    // an answer about the wrong field.
    $reader = new Asn1Reader();

    // A SEQUENCE of 4 bytes holding a child that declares 10.
    expect($reader->children("\x30\x04\x04\x0aabcd"))->toBe([]);
});

it('reads an object identifier as dotted text', function () {
    $reader = new Asn1Reader();

    // 1.2.840.113549.1.9.16.2.14, id-aa-timeStampToken.
    $der = "\x06\x0b\x2a\x86\x48\x86\xf7\x0d\x01\x09\x10\x02\x0e";
    $node = $reader->at($der);

    expect($node)->not->toBeNull()
        ->and($reader->oid($der, $node))->toBe('1.2.840.113549.1.9.16.2.14');

    // Not an OID at all.
    $other = "\x02\x01\x01";

    expect($reader->oid($other, $reader->at($other)))->toBeNull();
});

it('reads a generalized time, and refuses a form RFC 3161 does not use', function () {
    $reader = new Asn1Reader();

    $der = "\x18\x0f" . '20260806034651Z';
    $node = $reader->at($der);
    $expected = new DateTimeImmutable('2026-08-06 03:46:51', new DateTimeZone('UTC'))->getTimestamp();

    expect($node)->not->toBeNull()
        ->and($reader->generalizedTime($der, $node))->toBe($expected);

    // Fractional seconds are legal and are dropped rather than refused.
    $fractional = "\x18\x11" . '20260806034651.5Z';

    expect($reader->generalizedTime($fractional, $reader->at($fractional)))->toBe($expected);

    // A local-time form, which RFC 3161 §2.4.2 forbids: unreadable rather than
    // assumed to be UTC.
    $local = "\x18\x0e" . '20260806034651';

    expect($reader->generalizedTime($local, $reader->at($local)))->toBeNull();
});

it('reads an integer as the hex a serial number is compared as', function () {
    $reader = new Asn1Reader();

    // DER pads a positive integer whose top bit is set, and the pad is not part
    // of the serial openssl_x509_parse() reports.
    $padded = "\x02\x02\x00\xff";

    expect($reader->integerAsHex($padded, $reader->at($padded)))->toBe('FF');

    $zero = "\x02\x01\x00";

    expect($reader->integerAsHex($zero, $reader->at($zero)))->toBe('0');
});
