<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Validation\DerReader;

/**
 * Boundary cases in the DER length header.
 *
 * Mutation testing surfaced these: the happy-path tests sign real documents,
 * which only ever exercise one shape of length encoding, so every off-by-one
 * in this arithmetic survived. ISO/IEC 8825-1 §8.1.3 defines the rest.
 */
it('reads a short-form length', function () {
    // 0x30 SEQUENCE, length 3, three content bytes.
    $der = "\x30\x03\x01\x02\x03";

    expect(new DerReader()->declaredLength($der))->toBe(5)
        ->and(new DerReader()->truncate($der . str_repeat("\x00", 40)))->toBe($der);
});

it('reads a length of zero', function () {
    expect(new DerReader()->declaredLength("\x30\x00"))->toBe(2);
});

it('reads the largest short form, one below the long-form marker', function () {
    $der = "\x30\x7F" . str_repeat('x', 0x7F);

    expect(new DerReader()->declaredLength($der))->toBe(0x81);
});

it('reads a one-byte long form', function () {
    // 0x81 = long form with one length byte.
    $der = "\x30\x81\x80" . str_repeat('x', 0x80);

    expect(new DerReader()->declaredLength($der))->toBe(3 + 0x80);
});

it('reads a two-byte long form, the shape a certificate takes', function () {
    $der = "\x30\x82\x01\x00" . str_repeat('x', 256);

    expect(new DerReader()->declaredLength($der))->toBe(4 + 256);
});

it('reads a four-byte long form', function () {
    $der = "\x30\x84\x00\x00\x01\x00" . str_repeat('x', 256);

    expect(new DerReader()->declaredLength($der))->toBe(6 + 256);
});

it('rejects the indefinite form, which DER forbids', function () {
    expect(new DerReader()->declaredLength("\x30\x80" . str_repeat('x', 10)))->toBe(0)
        ->and(new DerReader()->truncate("\x30\x80xxxx"))->toBe('');
});

it('rejects a header cut short', function () {
    $reader = new DerReader();

    expect($reader->declaredLength(''))->toBe(0)
        ->and($reader->declaredLength("\x30"))->toBe(0)
        // Claims four length bytes but carries two.
        ->and($reader->declaredLength("\x30\x84\x00\x00"))->toBe(0);
});

it('refuses to truncate a structure that runs past the buffer', function () {
    // Declares 256 content bytes but only ten follow.
    expect(new DerReader()->truncate("\x30\x82\x01\x00" . str_repeat('x', 10)))->toBe('');
});

it('keeps trailing zero bytes that belong to the structure', function () {
    // The bug this class exists for: rtrim() would eat these.
    $der = "\x30\x04\x01\x02\x00\x00";

    expect(new DerReader()->truncate($der . str_repeat("\x00", 20)))->toBe($der)
        ->and(strlen(new DerReader()->truncate($der)))->toBe(6);
});
