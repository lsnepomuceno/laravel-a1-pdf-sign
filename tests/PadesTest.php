<?php

use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

it('describes what each profile requires', function () {
    expect(SignatureProfile::Legacy->isPades())->toBeFalse()
        ->and(SignatureProfile::Legacy->subFilter())->toBe('adbe.pkcs7.detached')
        ->and(SignatureProfile::PadesBB->isPades())->toBeTrue()
        ->and(SignatureProfile::PadesBB->subFilter())->toBe('ETSI.CAdES.detached')
        ->and(SignatureProfile::PadesBB->needsTimestamp())->toBeFalse()
        ->and(SignatureProfile::PadesBT->needsTimestamp())->toBeTrue()
        ->and(SignatureProfile::PadesBLT->needsValidationMaterial())->toBeTrue()
        ->and(SignatureProfile::PadesBLTA->needsValidationMaterial())->toBeTrue();
});

it('defaults to the PAdES baseline', function () {
    expect(SignatureProfile::resolve(null))->toBe(SignatureProfile::PadesBB)
        ->and(SignatureProfile::resolve('pades-b-t'))->toBe(SignatureProfile::PadesBT)
        ->and(SignatureProfile::resolve('nonsense'))->toBe(SignatureProfile::PadesBB);
});

it('writes the CAdES sub-filter for a PAdES signature', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBB)
        ->sign();

    expect((string) $signed->contents)->toContain('/SubFilter/ETSI.CAdES.detached')
        ->not->toContain('/SubFilter/adbe.pkcs7.detached');
});

it('writes the legacy sub-filter when asked for it', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::Legacy)
        ->sign();

    expect($signed->contents)->toContain('/SubFilter/adbe.pkcs7.detached');
});

it('embeds the ESS signing-certificate-v2 attribute openssl cannot produce', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    preg_match('/\/Contents\s*<([0-9a-fA-F]+)>/', $signed->contents, $matches);
    $hex = rtrim($matches[1] ?? '', '0');
    $der = (string) hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);

    // OID 1.2.840.113549.1.9.16.2.47, id-aa-signingCertificateV2.
    $oid = hex2bin('2A864886F70D010910022F');

    expect($der)->toContain((string) $oid);
});

it('refuses a timestamped profile without a configured authority', function () {
    config()->set('a1-pdf-sign.signature.timestamp.url', null);

    [$pfxPath, $password] = debugCertificate();

    A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->timestamp()
        ->sign();
})->throws(ProcessRunTimeException::class, 'needs a timestamp authority');

it('signs at PAdES B-T against a live timestamp authority', function () {
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

    [$pfxPath, $password] = debugCertificate();

    $plain = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $stamped = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->timestamp()
        ->sign();

    $size = static function (string $pdf): int {
        preg_match('/\/Contents\s*<([0-9a-fA-F]+)>/', $pdf, $m);

        return strlen(rtrim($m[1] ?? '', '0'));
    };

    // The token is embedded as an unsigned attribute, so the CMS must grow.
    expect($size($stamped->contents))->toBeGreaterThan($size($plain->contents));
})->group('network');
