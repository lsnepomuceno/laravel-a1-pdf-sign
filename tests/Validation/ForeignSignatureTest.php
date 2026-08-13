<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Validation\PdfSignatureExtractor;

/**
 * Validation, pointed at a signature this package did not write.
 *
 * Every other validation test signs first and validates afterwards, and every
 * file in `samples/` is this package's own output. Decisions 0010 and 0019 say
 * so in their titles. The consequence went unnoticed until something else
 * produced a signed document: `Pkcs7Reader`, `DerReader` and
 * `PdfSignatureExtractor` had only ever been shown one producer's bytes, and
 * had quietly grown to fit them.
 *
 * `tests/Resources/foreign-signed.pdf` is `tests/Resources/test.pdf` signed by
 * **pyHanko 0.36.2** with `samples/certificate.pfx`, at PAdES B-B:
 *
 * ```bash
 * pip install "pyHanko==0.36.2" "pyhanko-cli==0.4.2"
 * pyhanko sign addsig --field Sig1 --use-pades \
 *     pkcs12 --passfile password.txt \
 *     tests/Resources/test.pdf tests/Resources/foreign-signed.pdf \
 *     samples/certificate.pfx
 * ```
 *
 * It is committed rather than generated, so this file needs nothing installed
 * and runs everywhere the suite runs. The certificate is the one `samples/`
 * documents, so the fixture is reproducible from what the repository already
 * carries.
 */

/**
 * @return array{0: string, 1: string} The document's bytes, and its path.
 */
function foreignSigned(): array
{
    $path = resource('foreign-signed.pdf');

    return [Files::read($path), $path];
}

it('reads a signature another producer wrote', function () {
    [$contents] = foreignSigned();

    $report = app(SignatureValidator::class)->validate($contents);

    expect($report->count())->toBe(1)
        ->and($report->isValid())->toBeTrue()
        ->and($report->latest()?->verified)->toBeTrue()
        ->and($report->latest()?->coversWholeDocument)->toBeTrue();
});

it('finds the byte range when the producer puts a space before the array', function () {
    [$contents] = foreignSigned();

    // The defect this file was added for. The pattern required the literal
    // `/ByteRange[0 `, so `/ByteRange [0 9875 15069 565]` matched nothing, the
    // extractor returned no entries, and a perfectly valid document raised as
    // unsigned. Invariant 4 covers exactly this and was written for the
    // signing side only.
    expect($contents)->toContain('/ByteRange [0 ')
        ->and(new PdfSignatureExtractor()->extract($contents))->toHaveCount(1);
});

it('reads the sub-filter when the producer writes it after the byte range', function () {
    [$contents] = foreignSigned();

    // This package writes /Type, /SubFilter and /ByteRange ahead of the
    // /Contents placeholder, so a backward window found them. pyHanko writes
    // /Contents first, which puts /SubFilter after the /ByteRange. Key order
    // inside a dictionary carries no meaning, so both are correct and only one
    // of them was being read.
    $entry = new PdfSignatureExtractor()->extract($contents)[0];

    expect($entry['subFilter'])->toBe('ETSI.CAdES.detached')
        ->and($entry['isTimestamp'])->toBeFalse();
});

it('derives the profile from what the foreign document declares', function () {
    [$contents] = foreignSigned();

    expect(app(SignatureValidator::class)->validate($contents)->latest()?->profile)
        ->toBe(SignatureProfile::PadesBB);
});

it('names the signer the foreign document carries', function () {
    [$contents] = foreignSigned();

    $signers = app(SignatureValidator::class)->validate($contents)->latest()->signers;

    expect($signers)->toHaveCount(1)
        ->and($signers[0]->commonName)->toBe('Test Certificate');
});
