<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\A1PdfSignException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificatePasswordException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

/**
 * What a consuming application can catch.
 *
 * The classes are granular on purpose, one per failure mode
 * (docs/decisions/0008-exceptions-name-the-real-fault.md). Until now that left
 * no way to catch them as a group: sixteen classes, or `\Exception` and
 * everything the framework throws with them.
 */
it('lets every failure be caught as one', function (string $class) {
    expect(class_exists($class) || interface_exists($class))->toBeTrue()
        ->and(is_a($class, A1PdfSignException::class, allow_string: true))->toBeTrue();
})->with(function () {
    $classes = [];

    $files = glob(dirname(__DIR__) . '/src/Exceptions/*.php');

    foreach ($files === false ? [] : $files as $file) {
        $name = 'LSNepomuceno\\LaravelA1PdfSign\\Exceptions\\' . basename($file, '.php');

        // The interface itself, which cannot implement itself.
        if ($name !== A1PdfSignException::class) {
            $classes[basename($file, '.php')] = $name;
        }
    }

    return $classes;
});

it('finds every exception rather than a list somebody has to remember', function () {
    // The dataset above is built from the directory, so a new exception is
    // covered the moment it exists. This asserts the directory is actually
    // being read, since a glob that silently returns nothing would make the
    // test above pass with no cases at all.
    expect(glob(dirname(__DIR__) . '/src/Exceptions/*.php'))->toHaveCount(18);
});

it('names a wrong password as a wrong password', function () {
    [$pfxPath, $password] = debugCertificate();

    expect(fn() => app(CertificateReader::class)->read((string) file_get_contents($pfxPath), 'not the password'))
        ->toThrow(InvalidCertificatePasswordException::class);
});

it('keeps a wrong password catchable as what it used to be', function () {
    // The new class extends the one this used to arrive as, so an application
    // already catching the general failure keeps working. That is what makes
    // this additive rather than breaking.
    [$pfxPath] = debugCertificate();

    expect(fn() => app(CertificateReader::class)->read((string) file_get_contents($pfxPath), 'wrong'))
        ->toThrow(InvalidCertificateContentException::class);
});

it('does not call a corrupt bundle a wrong password', function () {
    // The distinction is evidence rather than a guess: OpenSSL answers a bad
    // password with a MAC verify failure and a broken file with an ASN.1
    // error. Reading a string to tell them apart is what the class exists to
    // stop, so it must not simply guess "password" for every failure.
    try {
        app(CertificateReader::class)->read('this is not a PKCS#12 bundle at all', 'anything');
    } catch (InvalidCertificateContentException $exception) {
        expect($exception)->not->toBeInstanceOf(InvalidCertificatePasswordException::class);

        return;
    }

    expect(false)->toBeTrue();
});

it('is caught as a group through the facade, which is how an application meets it', function () {
    // The shape a consumer actually writes: one catch around the whole flow.
    try {
        A1PdfSign::newSignature()
            ->certificate(resource('test.pdf'), 'not a certificate')
            ->pdf(resource('test.pdf'))
            ->sign();
    } catch (A1PdfSignException $exception) {
        expect($exception)->toBeInstanceOf(Throwable::class);

        return;
    }

    expect(false)->toBeTrue();
});
