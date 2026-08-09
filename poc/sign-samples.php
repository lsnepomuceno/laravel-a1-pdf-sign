<?php

/**
 * Produces one signed sample per PAdES profile, for validation in Adobe Reader
 * or ITI Validar.
 *
 * The certificate is a throwaway self-signed one, so every reader will report
 * the signer as untrusted: that is the certificate's provenance, not the
 * signature's integrity. What can be validated here is everything else: the
 * document hash, the sub-filter, the timestamp token and the coverage of each
 * signature in the multi-signature sample.
 *
 *   docker compose -f .docker/compose.yaml run --rm php php poc/sign-samples.php
 */

use LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\LaravelA1PdfSignServiceProvider;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;
use Orchestra\Testbench\Foundation\Application;

require __DIR__ . '/../vendor/autoload.php';

$app = Application::create(basePath: __DIR__ . '/../vendor/orchestra/testbench-core/laravel');
$app->register(LaravelA1PdfSignServiceProvider::class);

$output = __DIR__ . '/../.output';

if (! is_dir($output)) {
    mkdir($output, 0o755, true);
}

[$pfx, $password] = DebugCertificate::make();
file_put_contents("{$output}/certificate.pfx", $pfx);

$pfxPath = "{$output}/certificate.pfx";
$pemPath = "{$output}/certificate.pem";
$source = __DIR__ . '/../tests/Resources/test.pdf';

$manager = $app->make(LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign::class);

// The same certificate as PEM, derived from the PFX rather than generated
// afresh, so samples/ carries one identity in two encodings instead of two
// unrelated certificates. The key is re-encrypted under the same password: a
// sample should not model shipping a naked private key, throwaway or not.
$bundle = $app->make(NativeCertificateReader::class)->read($pfx, $password)->original;

$privateKey = openssl_pkey_get_private($bundle);
openssl_pkey_export($privateKey, $encryptedKey, $password);
preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bundle, $certificate);

file_put_contents($pemPath, rtrim($certificate[0], "\n") . "\n" . $encryptedKey);

$config = $app->make('config');
$config->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

$samples = [
    'legacy' => SignatureProfile::Legacy,
    'pades-b-b' => SignatureProfile::PadesBB,
    'pades-b-t' => SignatureProfile::PadesBT,
    'pades-b-lt' => SignatureProfile::PadesBLT,
    'pades-b-lta' => SignatureProfile::PadesBLTA,
];

foreach ($samples as $name => $profile) {
    $signed = $manager->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($source)
        ->info(name: 'Lucas Nepomuceno', reason: 'Sample', location: 'Brazil')
        ->seal()
        ->profile($profile)
        ->sign();

    $signed->save("{$output}/{$name}.pdf");

    printf("%-14s %8d bytes\n", $name, $signed->size());
}

// Six signatures over the same document: the point TCPDF#430 could not reach.
$multi = "{$output}/six-signatures.pdf";
copy($source, $multi);

for ($round = 1; $round <= 6; $round++) {
    $signed = $manager->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($multi)
        ->info(name: "Signer {$round}", reason: "Round {$round}")
        ->profile(SignatureProfile::PadesBB)
        ->sign();

    $signed->save($multi);

    printf("signature %d    %8d bytes\n", $round, $signed->size());
}

$report = $manager->validate($multi);

printf(
    "\nvalidate(six-signatures.pdf): %d signatures, valid=%s\n",
    $report->count(),
    $report->isValid() ? 'true' : 'false',
);

// Two signatures on one document, each carrying its own seal. The first is
// rendered from the certificate, the second is an image the caller supplies,
// and they sit in different places on the page. Worth a sample of its own
// because it is the combination nothing else here shows: seal.pdf proves a
// seal, six-signatures.pdf proves the revisions, and only this proves the two
// are independent when a reader opens the file.
$twoSeals = "{$output}/two-seals.pdf";

$first = $manager->newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($source)
    ->info(name: 'First signer', reason: 'Rendered seal')
    ->seal(placement: new SealPlacement(x: 150, y: 240, width: 50))
    ->profile(SignatureProfile::PadesBB)
    ->sign();

$first->save($twoSeals);

$second = $manager->newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($twoSeals)
    ->info(name: 'Second signer', reason: 'Supplied image')
    ->sealFrom(__DIR__ . '/../src/Resources/img/sign-seal.png', new SealPlacement(x: 30, y: 60, width: 60))
    ->profile(SignatureProfile::PadesBB)
    ->sign();

$second->save($twoSeals);

$twoSealsReport = $manager->validate($twoSeals);

printf(
    "validate(two-seals.pdf):      %d signatures, valid=%s, images=%d\n",
    $twoSealsReport->count(),
    $twoSealsReport->isValid() ? 'true' : 'false',
    substr_count((string) $second->contents, '/Subtype/Image'),
);

// One round through the PEM entry point. There is no pem-signed.pdf in
// samples/ on purpose: the encoding only changes how the key is loaded, so the
// signature is indistinguishable from the PKCS#12 one and a separate sample
// would imply a distinction that does not exist (§3i). What this proves is that
// the two entries converge on real output, not only in a unit test.
$viaPem = $manager->newSignature()
    ->certificatePem($pemPath, password: $password)
    ->pdf($source)
    ->info(name: 'Lucas Nepomuceno', reason: 'Sample', location: 'Brazil')
    ->seal()
    ->profile(SignatureProfile::PadesBB)
    ->sign();

$viaPem->save("{$output}/pem-signed.pdf");

$pemReport = $manager->validate("{$output}/pem-signed.pdf");

printf(
    "validate(pem-signed.pdf):     %d signature, valid=%s, signer=%s\n",
    $pemReport->count(),
    $pemReport->isValid() ? 'true' : 'false',
    $pemReport->signers()[0]->commonName ?? '?',
);
