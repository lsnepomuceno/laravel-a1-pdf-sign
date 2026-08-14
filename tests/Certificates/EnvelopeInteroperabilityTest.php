<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Encryption\Encrypter;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateVault;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Support\SodiumEncrypter;

/**
 * A certificate sealed by `lsnepomuceno/signet-pdf` has to open here.
 *
 * An application moving between the two packages cannot re-encrypt material
 * whose plaintext it no longer holds, which is why both have always read each
 * other's storage. signet-pdf 2.0 moved new material onto XChaCha20-Poly1305,
 * and this is the reader that keeps the guarantee true
 * (docs/decisions/0038-the-envelope-is-versioned.md).
 */
it('opens a payload sealed by signet-pdf itself', function () {
    // Not a round-trip through this class, which would pass even if both
    // halves were wrong together. These bytes were produced by
    // `LSNepomuceno\Signet\Support\SodiumEncrypter` in signet-pdf 2.0 and
    // pasted here, so the test fails if either package moves.
    $key = base64_decode('bamDDgPD9Ge8TS2z4BNFEIef/a9RBdy6WBRzUoWYamY=', true);
    $payload = 'signet.v2.7PhSd7EHWJ2J21M5lp84yIvWSNoGG6Ege0omBNxjsxen'
        . 'IuTKhTHfym37mK3I/jl4h/4KfI/BM9h5KQfRC7J1mA==';

    expect($key)->toBeString()
        ->and(new SodiumEncrypter((string) $key)->decryptString($payload))
        ->toBe('sealed by signet-pdf 2.0')
        // And through the vault, which is how an application reaches it.
        ->and(CertificateVault::withKey((string) $key)->encrypter()->decryptString($payload))
        ->toBe('sealed by signet-pdf 2.0');
});

it('round-trips the envelope signet-pdf writes', function () {
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());
    $sealed = $encrypter->encryptString('a certificate, notionally');

    expect($sealed)->toStartWith(SodiumEncrypter::PREFIX)
        ->and($encrypter->decryptString($sealed))->toBe('a certificate, notionally');
});

it('authenticates the version marker rather than merely carrying it', function () {
    // The marker is the additional data, so relabelling has to fail the tag. A
    // prefix outside the tag would be the downgrade the versioning prevents.
    $key = SodiumEncrypter::generateKey();
    $sealed = new SodiumEncrypter($key)->encryptString('secret');

    $relabelled = 'signet.v9.' . substr($sealed, strlen(SodiumEncrypter::PREFIX));

    expect(fn() => new SodiumEncrypter($key)->decryptString($relabelled))
        ->toThrow(DecryptException::class);
});

it('refuses a payload sealed under another key', function () {
    $sealed = new SodiumEncrypter(SodiumEncrypter::generateKey())->encryptString('secret');

    expect(fn() => new SodiumEncrypter(SodiumEncrypter::generateKey())->decryptString($sealed))
        ->toThrow(DecryptException::class, 'sealed with a different key, or has been tampered with');
});

it('refuses a payload too short to hold a nonce and a tag', function () {
    $encrypter = new SodiumEncrypter(SodiumEncrypter::generateKey());

    expect(fn() => $encrypter->decryptString(SodiumEncrypter::PREFIX . base64_encode('short')))
        ->toThrow(DecryptException::class, 'not a valid envelope');
});

it('refuses a key that is not the length libsodium requires', function () {
    expect(fn() => new SodiumEncrypter('too short'))
        ->toThrow(EncryptException::class, 'must be 32 bytes');
});

it('sends each envelope to the reader that understands it', function () {
    $sodium = new SodiumEncrypter(SodiumEncrypter::generateKey());

    expect(fn() => $sodium->decryptString(
        new Encrypter(Encrypter::generateKey(CertificateVault::CIPHER), CertificateVault::CIPHER)
            ->encryptString('x'),
    ))->toThrow(DecryptException::class, 'predates the current envelope');
});

it('picks the vault encrypter from the length of the key', function () {
    expect(CertificateVault::withKey(SodiumEncrypter::generateKey())->encrypter())
        ->toBeInstanceOf(SodiumEncrypter::class)
        ->and(CertificateVault::withKey(Encrypter::generateKey(CertificateVault::CIPHER))->encrypter())
        ->toBeInstanceOf(Encrypter::class);
});

it('keeps writing the envelope this package has always written', function () {
    // A compatibility fix, not a migration: what an application stores today
    // it keeps storing, at the same width.
    $vault = CertificateVault::create();

    expect($vault->encrypter())->toBeInstanceOf(Encrypter::class)
        ->and(strlen($vault->key()))->toBe(CertificateVault::KEY_LENGTH);
});

it('refuses a vault key belonging to neither envelope', function () {
    expect(fn() => CertificateVault::withKey(random_bytes(24)))
        ->toThrow(InvalidCertificateContentException::class, '24 given');
});

it('reports the key it was built with, not one read back off the encrypter', function () {
    $key = SodiumEncrypter::generateKey();

    expect(CertificateVault::withKey($key)->key())->toBe($key);
});
