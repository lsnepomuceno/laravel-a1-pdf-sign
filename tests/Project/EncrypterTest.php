<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Adapters\IlluminateEncrypter;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\Signet\Certificates\{CertificateParser, CertificateVault};
use LSNepomuceno\Signet\Exceptions\EncryptionException;

it('seals with Laravel, in the envelope 2.x wrote', function () {
    [$pfxPath, $password] = debugCertificate();

    $sealed = A1PdfSign::encryptCertificate($pfxPath, $password);

    // 16 bytes is AES-128-CBC, which is what says the envelope is this
    // package's rather than the engine's 32-byte XChaCha20-Poly1305 one.
    expect(strlen($sealed->hash))->toBe(16);

    $envelope = json_decode((string) base64_decode($sealed->certificate, true), true);

    expect($envelope)->toBeArray()
        ->and($envelope)->toHaveKeys(['iv', 'value', 'mac', 'tag']);
});

it('reads back what it sealed', function () {
    [$pfxPath, $password] = debugCertificate();

    $sealed = A1PdfSign::encryptCertificate($pfxPath, $password);

    // The third argument is the sealed password, not the plaintext one: both
    // halves come back out of the envelope.
    $certificate = A1PdfSign::decryptCertificate($sealed->hash, $sealed->certificate, $sealed->password);

    expect($certificate->original)->toContain('BEGIN CERTIFICATE');
});

it('is read by the engine, which keys the reader off the key length', function () {
    [$pfxPath, $password] = debugCertificate();

    $sealed = A1PdfSign::encryptCertificate($pfxPath, $password);

    // The vault is the engine's, with no Laravel in sight, and it still opens
    // the material: docs/decisions/0038-the-envelope-is-versioned.md.
    $certificate = CertificateVault::withKey($sealed->hash)
        ->open(new CertificateParser(), $sealed->certificate, $sealed->password);

    expect($certificate->original)->toContain('BEGIN CERTIFICATE');
});

it('refuses a key the cipher cannot take', function () {
    new IlluminateEncrypter('too short');
})->throws(EncryptionException::class);

it('reports a payload sealed under another key rather than returning rubbish', function () {
    $sealed = IlluminateEncrypter::generate()->encryptString('a certificate');

    new IlluminateEncrypter(IlluminateEncrypter::generate()->key())->decryptString($sealed);
})->throws(EncryptionException::class);
