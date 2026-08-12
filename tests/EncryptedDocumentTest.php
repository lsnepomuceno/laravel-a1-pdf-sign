<?php

use LSNepomuceno\LaravelA1PdfSign\Enums\EncryptionAlgorithm;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * Signing a document that is encrypted.
 *
 * [0014](docs/decisions/0014-refuse-encrypted-documents.md) refused these,
 * correctly: the cross-reference table is not encrypted, so reading gets far
 * enough to look successful while the strings and streams around it are
 * unreadable, and the plaintext revision written beside them produces a file
 * whose new objects no reader can decrypt. That record also said what the real
 * fix would be, and this is it
 * ([0030](docs/decisions/0030-signing-a-document-that-is-encrypted.md)).
 *
 * The fixtures are qpdf's output, so the key derivation is checked against an
 * implementation that is not this one. qpdf then reads the signed result back,
 * which is the half that matters: our own reader agreeing with our own writer
 * would prove nothing.
 */

/**
 * Signs an encrypted fixture and hands back the path.
 */
function signedEncrypted(string $fixture, string $password = 'secret'): string
{
    [$pfxPath, $certificatePassword] = debugCertificate();

    return A1PdfSign::newSignature()
        ->certificate($pfxPath, $certificatePassword)
        ->pdf(resource("encrypted-{$fixture}.pdf"), $password)
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));
}

it('opens a document with the password it was given', function (string $fixture, EncryptionAlgorithm $algorithm) {
    $document = app(DocumentReader::class)->read(Files::read(resource("encrypted-{$fixture}.pdf")), 'secret');

    expect($document->isEncrypted())->toBeTrue()
        ->and($document->security?->dictionary->algorithm)->toBe($algorithm)
        ->and(strlen((string) $document->security?->key))->toBe($algorithm === EncryptionAlgorithm::Aes256 ? 32 : 16);
})->with([
    ['aes256', EncryptionAlgorithm::Aes256],
    ['aes128', EncryptionAlgorithm::Aes128],
]);

it('refuses a password that does not open the document', function () {
    // The failure this replaces was silent. A wrong password derives a key that
    // encrypts to noise, and noise appended beside a document is exactly the
    // corruption 0014 refused to produce.
    expect(fn() => app(DocumentReader::class)->read(Files::read(resource('encrypted-aes256.pdf')), 'wrong'))
        ->toThrow(InvalidPdfFileException::class, 'the password does not open this document');
});

it('still refuses a document encrypted with RC4', function () {
    // Reading it is possible. Signing means writing RC4 back into the file, and
    // this package will not weaken a document in order to sign it.
    expect(fn() => app(DocumentReader::class)->read(Files::read(resource('encrypted-rc4.pdf')), 'secret'))
        ->toThrow(InvalidPdfFileException::class, 'encrypted with RC4');
});

it('signs an encrypted document and qpdf reads the result', function (string $fixture) {
    // The check that matters, and the reason the fixtures come from qpdf: an
    // implementation that is not this one decrypts what this one wrote. Our
    // reader agreeing with our writer would prove nothing at all.
    $path = signedEncrypted($fixture);

    $complaints = qpdfComplaintsAbout($path, 'secret');

    expect($complaints)->toBe([]);

    unlink($path);
})->with(['aes256', 'aes128']);

it('leaves the signature contents unencrypted, which is the one exemption', function () {
    // ISO 32000-1 §7.6.2: /Contents is excluded from encryption, because it is
    // the signature over the file rather than content of it. Encrypting it
    // would produce a document that decrypts perfectly and verifies nowhere.
    $path = signedEncrypted('aes256');
    $signed = Files::read($path);

    // The placeholder is written as hex and filled in place, so what survives
    // in the file is the CMS itself: it starts with the DER SEQUENCE every
    // PKCS#7 blob starts with.
    preg_match('/\/Contents\s*<([0-9a-fA-F]+)>/', $signed, $found);

    expect(substr((string) hex2bin(rtrim($found[1] ?? '', '0')), 0, 1))->toBe("\x30");

    unlink($path);
});

it('encrypts the strings it writes beside it', function () {
    // The field name is an ordinary string and has to be encrypted like any
    // other, which means it stops being readable in the raw bytes. A document
    // where it is still legible is one where this was forgotten.
    $path = signedEncrypted('aes256');
    $signed = Files::read($path);

    $appended = substr($signed, strlen(Files::read(resource('encrypted-aes256.pdf'))));

    expect($appended)->not->toContain('/T (Signature')
        ->and($appended)->toMatch('#/T <[0-9a-f]+>#');

    unlink($path);
});

it('encrypts the seal it draws', function () {
    // A stream is encrypted after it is compressed, and the length written is
    // the encrypted length. Getting that pair wrong produces a file that looks
    // fine until a reader walks past the end of the stream.
    [$pfxPath, $certificatePassword] = debugCertificate();

    $path = A1PdfSign::newSignature()
        ->certificate($pfxPath, $certificatePassword)
        ->pdf(resource('encrypted-aes256.pdf'), 'secret')
        ->seal()
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    expect(qpdfComplaintsAbout($path, 'secret'))->toBe([]);

    unlink($path);
});

it('refuses the profiles that append streams it does not encrypt', function () {
    // B-LT and above append a security store and an archive timestamp of their
    // own, built by tc-lib rather than here, so their streams would go in
    // unencrypted. Refusing beats writing revisions no reader can decode, which
    // is the same reasoning 0014 applied to the whole document.
    [$pfxPath, $certificatePassword] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $certificatePassword)
        ->pdf(resource('encrypted-aes256.pdf'), 'secret')
        ->profile(SignatureProfile::PadesBLT)
        ->sign())
        ->toThrow(InvalidPdfFileException::class, 'can be signed up to pades-b-t');
});

it('says what is wrong when no password is given at all', function () {
    expect(fn() => app(DocumentReader::class)->read(Files::read(resource('encrypted-aes256.pdf'))))
        ->toThrow(InvalidPdfFileException::class, 'the password does not open this document');
});
