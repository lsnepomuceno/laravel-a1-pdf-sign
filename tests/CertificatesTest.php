<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateParser;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateVault;
use LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\OpenSslCliCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\PemCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\ReaderFactory;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPemContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;

it('reads a PKCS#12 bundle natively, without touching disk or a shell', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = app(NativeCertificateReader::class)->read($pfx, $password);

    expect($certificate)->toBeInstanceOf(Certificate::class)
        ->and($certificate->original)->toContain('BEGIN CERTIFICATE')
        ->and($certificate->original)->toContain('PRIVATE KEY')
        ->and($certificate->commonName())->toBe('Test Certificate')
        ->and($certificate->isExpired())->toBeFalse();
});

it('rejects a wrong password with a reason', function () {
    [$pfx] = DebugCertificate::make();

    app(NativeCertificateReader::class)->read($pfx, 'not-the-password');
})->throws(InvalidCertificateContentException::class);

it('produces the same PEM through the CLI reader', function () {
    [$pfx, $password] = DebugCertificate::make();

    $native = app(NativeCertificateReader::class)->read($pfx, $password);
    $cli = app(ReaderFactory::class)->make(legacy: true)->read($pfx, $password);

    expect($cli)->toBeInstanceOf(Certificate::class)
        // Both drivers must yield an interchangeable bundle, otherwise the
        // legacy fallback would silently change what gets signed.
        ->and($cli->data['subject'])->toBe($native->data['subject'])
        ->and($cli->original)->toContain('BEGIN CERTIFICATE');
});

it('selects the reader from the legacy flag', function () {
    $factory = app(ReaderFactory::class);

    expect($factory->make(legacy: false))->toBeInstanceOf(NativeCertificateReader::class)
        ->and($factory->make(legacy: true))->toBeInstanceOf(OpenSslCliCertificateReader::class);
});

it('seals and opens a certificate through the vault', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = app(NativeCertificateReader::class)->read($pfx, $password);

    $vault = CertificateVault::create();
    $sealed = $vault->seal($certificate, $password);

    $opened = CertificateVault::withKey($sealed->hash)
        ->open(app(CertificateParser::class), $sealed->certificate, $sealed->password);

    expect($opened->original)->toBe($certificate->original)
        ->and($opened->password)->toBe($password);
});

it('deletes a temporary file even when the callback throws', function () {
    $path = null;

    try {
        TemporaryFile::with(sys_get_temp_dir(), '.tmp', 'x', function (TemporaryFile $file) use (&$path) {
            $path = $file->path;

            expect(File::exists($path))->toBeTrue();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(File::exists((string) $path))->toBeFalse();
});

it('generates a debug certificate without shelling out', function () {
    [$pfx, $password] = DebugCertificate::make();

    expect($pfx)->not->toBeEmpty()
        ->and($password)->toBe(DebugCertificate::PASSWORD)
        ->and(app(NativeCertificateReader::class)->read($pfx, $password))
        ->toBeInstanceOf(Certificate::class);
});

it('rejects a bundle whose key does not match its certificate', function () {
    // One certificate, a different key. Nothing had exercised this path, which
    // is how a case mismatch in the exception's own name went unnoticed: the
    // class was never autoloaded, so it would have fataled rather than thrown.
    [$pfxA, $passwordA] = DebugCertificate::make();
    [$pfxB, $passwordB] = DebugCertificate::make();

    $reader = app(NativeCertificateReader::class);

    $certificate = $reader->read($pfxA, $passwordA)->original;
    $other = $reader->read($pfxB, $passwordB)->original;

    preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate, $cert);
    preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $other, $key);

    app(CertificateParser::class)->parse(($cert[0] ?? '') . "\n" . ($key[0] ?? '') . "\n");
})->throws(InvalidX509PrivateKeyException::class);

/*
|--------------------------------------------------------------------------
| PEM bundles
|--------------------------------------------------------------------------
|
| The parser has always accepted PEM: every reader converges on it. What it
| could not do is validate a passphrase-protected private key, because the
| bundle was handed to openssl_x509_check_private_key() as a bare string.
| PKCS#12 never reached that path: openssl_pkcs12_read() returns a key that is
| already decrypted. See docs/decisions/0007-pem-second-entry-one-pipeline.md.
|
*/

it('parses a PEM bundle whose private key is encrypted', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    expect($privateKey)->toContain('ENCRYPTED');

    $parsed = app(CertificateParser::class)->parse($certificate . $privateKey, $password);

    expect($parsed)->toBeInstanceOf(Certificate::class)
        ->and($parsed->commonName())->toBe('Test Certificate')
        ->and($parsed->password)->toBe($password);
});

it('rejects an encrypted PEM key when the passphrase is wrong', function () {
    [$certificate, $privateKey] = DebugCertificate::makePem();

    app(CertificateParser::class)->parse($certificate . $privateKey, 'not-the-passphrase');
})->throws(InvalidX509PrivateKeyException::class);

it('still parses a PEM bundle whose private key is unencrypted', function () {
    // The array form has to serve both cases, otherwise the fix trades one
    // broken input for another.
    [$certificate, $privateKey, $password] = DebugCertificate::makePem(encryptKey: false);

    expect($password)->toBe('')
        ->and($privateKey)->not->toContain('ENCRYPTED')
        ->and(app(CertificateParser::class)->parse($certificate . $privateKey, $password))
        ->toBeInstanceOf(Certificate::class);
});

it('reads a combined PEM bundle', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    expect(app(PemCertificateReader::class)->read($certificate . $privateKey, $password))
        ->toBeInstanceOf(Certificate::class)
        ->commonName()->toBe('Test Certificate');
});

it('reads a certificate and a private key that arrived separately', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    expect(app(PemCertificateReader::class)->readPair($certificate, $privateKey, $password))
        ->toBeInstanceOf(Certificate::class)
        ->commonName()->toBe('Test Certificate');
});

it('does not care whether the key comes before the certificate', function () {
    [$certificate, $privateKey, $password] = DebugCertificate::makePem();

    $reader = app(PemCertificateReader::class);

    expect($reader->read($privateKey . $certificate, $password))
        ->toBeInstanceOf(Certificate::class);
});

it('reads an unencrypted PEM bundle without being given a password', function () {
    [$certificate, $privateKey] = DebugCertificate::makePem(encryptKey: false);

    expect(app(PemCertificateReader::class)->read($certificate . $privateKey))
        ->toBeInstanceOf(Certificate::class);
});

it('tells binary bytes apart from PEM instead of reporting them as malformed', function () {
    // A .pfx handed to the PEM entry point is the mistake this catches; without
    // it openssl_x509_read() just fails, and the message blames the content.
    [$pfx] = DebugCertificate::make();

    app(PemCertificateReader::class)->read($pfx);
})->throws(InvalidPemContentException::class, 'binary DER or PKCS#12 bytes');

it('rejects a PEM carrying no private key', function () {
    [$certificate] = DebugCertificate::makePem();

    app(PemCertificateReader::class)->read($certificate);
})->throws(InvalidPemContentException::class, 'No PEM private key block found in the bundle');

it('names the offending half when the same file is passed twice', function () {
    [$certificate] = DebugCertificate::makePem();

    app(PemCertificateReader::class)->readPair($certificate, $certificate);
})->throws(InvalidPemContentException::class, 'No PEM private key block found in the private key');

it('rejects text that is neither PEM nor binary', function () {
    app(PemCertificateReader::class)->read('this is not a certificate');
})->throws(InvalidPemContentException::class, 'No PEM certificate block found in the bundle');

it('still reports a key that does not match its certificate as such', function () {
    // The format is fine here, so this is not a PEM problem: it keeps the
    // exception that already says exactly this, rather than a second one.
    [$certificate] = DebugCertificate::makePem();
    [, $otherKey, $otherPassword] = DebugCertificate::makePem();

    app(PemCertificateReader::class)->readPair($certificate, $otherKey, $otherPassword);
})->throws(InvalidX509PrivateKeyException::class);

it('agrees with the PKCS#12 path on the same key material', function () {
    // The executable form of "one pipeline, two entries": if these ever stop
    // agreeing, the PEM path has forked from the PKCS#12 one in practice.
    [$pfx, $password] = DebugCertificate::make();

    $viaPkcs12 = app(NativeCertificateReader::class)->read($pfx, $password);
    $viaPem = app(PemCertificateReader::class)->read($viaPkcs12->original);

    expect($viaPem->original)->toBe($viaPkcs12->original)
        ->and($viaPem->data['subject'])->toBe($viaPkcs12->data['subject'])
        ->and($viaPem->data['serialNumber'])->toBe($viaPkcs12->data['serialNumber']);
});
