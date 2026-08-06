<?php

/**
 * Produces one signed sample per PAdES profile, for validation in Adobe Reader
 * or ITI Validar.
 *
 * The certificate is a throwaway self-signed one, so every reader will report
 * the signer as untrusted — that is the certificate's provenance, not the
 * signature's integrity. What can be validated here is everything else: the
 * document hash, the sub-filter, the timestamp token and the coverage of each
 * signature in the multi-signature sample.
 *
 *   docker compose -f .docker/compose.yaml run --rm php php poc/sign-samples.php
 */

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
$source = __DIR__ . '/../tests/Resources/test.pdf';

$manager = $app->make(LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign::class);

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
