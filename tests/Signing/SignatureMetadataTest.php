<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

/**
 * What the signature dictionary says about the signer, and how it says it.
 *
 * *ISO 32000-1 §7.9.2.2, Table 35: a text string is PDFDocEncoding, or UTF-16BE
 * with a leading byte order mark.* Raw UTF-8 is neither, and it was what this
 * package wrote: the file verified, and a conforming reader displayed `João` as
 * `JoÃ£o` because it decoded the two bytes of `ã` as two PDFDocEncoding
 * characters.
 *
 * Nothing caught it because every assertion here used to be about the
 * signature verifying, and it did. The metadata was wrong in a document that
 * was correct.
 */

/**
 * Signs `test.pdf` with the given metadata and hands back the bytes.
 */
function signedWithInfo(string $name, string $reason = 'Contract'): string
{
    [$pfxPath, $password] = debugCertificate();

    return A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: $name, reason: $reason)
        ->sign()
        ->contents;
}

/**
 * The value of a key in the signature dictionary, decoded from whichever of the
 * two forms it was written in.
 */
function metadataValue(string $pdf, string $key): ?string
{
    if (preg_match('/\/' . $key . '\s*<([0-9A-Fa-f]+)>/', $pdf, $hex) === 1) {
        $raw = (string) hex2bin($hex[1]);

        return str_starts_with($raw, "\xFE\xFF")
            ? (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE')
            : $raw;
    }

    if (preg_match('/\/' . $key . '\s*\((.*?)(?<!\\\\)\)/s', $pdf, $literal) === 1) {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $literal[1]);
    }

    return null;
}

it('writes an ASCII name as a literal, unchanged', function () {
    // The output for an unaccented signer must not move. Every committed
    // sample, and every PDF/A verdict measured against them, was produced this
    // way.
    $signed = signedWithInfo('Lucas Nepomuceno');

    expect($signed)->toContain('/Name (Lucas Nepomuceno)')
        ->and(metadataValue($signed, 'Name'))->toBe('Lucas Nepomuceno');
});

it('still escapes the characters that would end the string early', function () {
    // The escaping was already right, and is the part this class of bug is
    // usually reported as. It has to survive the encoding change.
    $signed = signedWithInfo('Silva (Jr) \\ Co');

    expect($signed)->toContain('/Name (Silva \(Jr\) \\\\ Co)')
        ->and(metadataValue($signed, 'Name'))->toBe('Silva (Jr) \\ Co');
});

it('writes a name outside PDFDocEncoding as UTF-16BE with a byte order mark', function (string $name) {
    $signed = signedWithInfo($name);

    // Case-insensitive: a PDF hex string is, and bin2hex() writes lowercase.
    expect($signed)->toMatch('/\/Name\s*<feff[0-9a-f]+>/i')
        ->and(metadataValue($signed, 'Name'))->toBe($name);
})->with([
    'accents' => 'João da Silva Áureo',
    'emoji' => 'Lucas 🎉',
    'cyrillic' => 'Лукас Непомусено',
    'both, with the characters that need escaping' => 'João (Jr) \\ 🎉',
]);

it('encodes before escaping, so a character whose bytes look like a delimiter survives', function () {
    // U+0128 is 01 28 in UTF-16BE, and 0x28 is '('. Escaping the encoded bytes
    // as if they were text would split the character in half. Writing the whole
    // string in hex is what makes the question not arise.
    $signed = signedWithInfo("Ĩ");

    expect(metadataValue($signed, 'Name'))->toBe("Ĩ");
});

it('applies the same encoding to every metadata key, not only the name', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(
            name: 'João',
            location: 'São Paulo',
            reason: 'Aprovação do contrato',
            contactInfo: 'joão@example.com',
        )
        ->sign()
        ->contents;

    expect(metadataValue($signed, 'Name'))->toBe('João')
        ->and(metadataValue($signed, 'Location'))->toBe('São Paulo')
        ->and(metadataValue($signed, 'Reason'))->toBe('Aprovação do contrato')
        ->and(metadataValue($signed, 'ContactInfo'))->toBe('joão@example.com');
});

it('keeps the signature valid whatever the metadata says', function () {
    // The metadata sits inside the range the signature covers, so an encoding
    // change is a change to signed bytes: getting it wrong breaks verification
    // rather than only display.
    $signed = signedWithInfo('João da Silva 🎉', "Contrato (importante)");

    expect(app(SignatureValidator::class)->validate($signed)->isValid())->toBeTrue();
});

it('encodes before encrypting, so the mark ends up inside the ciphertext', function () {
    // A reader decrypts and then interprets the plaintext as a text string. A
    // byte order mark added after encryption would be a mark over ciphertext,
    // which decodes to nothing anybody can read.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('encrypted-aes256.pdf'), 'secret')
        ->info(name: 'João da Silva')
        ->sign()
        ->contents;

    // Encrypted strings are hex either way, so the assertion is that the
    // document still verifies and that no raw UTF-8 leaked out beside it.
    expect(app(SignatureValidator::class)->validate($signed)->isValid())->toBeTrue()
        ->and($signed)->not->toContain('/Name (João');
});
