<?php

/**
 * Certifies a document at each level, so a real reader can be asked whether it
 * honours the /DocMDP transform.
 *
 * The suite cannot answer this one. Whether a reader enforces a certification
 * is precisely what varies between readers, and a test asserting the bytes were
 * written is necessary and nowhere near sufficient
 * (docs/decisions/0012-certification-signatures.md).
 *
 *   docker compose -f .docker/compose.yaml run --rm php php poc/certify.php
 *   pdfsig .output/certified-no-changes.pdf
 */

use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
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
file_put_contents("{$output}/certify.pfx", $pfx);

$manager = $app->make(A1PdfSign::class);
$source = __DIR__ . '/../tests/Resources/test.pdf';

foreach (CertificationLevel::cases() as $level) {
    $path = "{$output}/certified-{$level->value}.pdf";

    $certified = $manager->newSignature()
        ->certificate("{$output}/certify.pfx", $password)
        ->pdf($source)
        ->certify($level)
        ->info(name: 'Lucas Nepomuceno', reason: 'Certification', location: 'Brazil')
        ->seal()
        ->sign();

    $certified->save($path);

    $report = $manager->validate($path);

    printf(
        "%-14s %8d bytes  certified=%-5s level=%-12s accepts more=%s\n",
        $level->value,
        $certified->size(),
        $report->isCertified() ? 'true' : 'false',
        $report->certification?->value ?? '-',
        $report->acceptsFurtherSignatures() ? 'true' : 'false',
    );
}

echo "\n";

// The exclusion the record exists to make obvious. A certification at
// no-changes forbids the revision another signature would append, so the second
// signature is refused rather than allowed to invalidate the first.
try {
    $manager->newSignature()
        ->certificate("{$output}/certify.pfx", $password)
        ->pdf("{$output}/certified-no-changes.pdf")
        ->sign();

    echo "SIGNING A LOCKED DOCUMENT WAS ALLOWED, which is the bug this exists to prevent\n";
} catch (Throwable $exception) {
    echo 'refused, correctly: ' . $exception->getMessage() . "\n";
}

// form-filling exists precisely so the document can still be signed.
$alsoSigned = $manager->newSignature()
    ->certificate("{$output}/certify.pfx", $password)
    ->pdf("{$output}/certified-form-filling.pdf")
    ->info(name: 'Second signer', reason: 'Approval after certification')
    ->sign();

$alsoSigned->save("{$output}/certified-then-signed.pdf");

$report = $manager->validate("{$output}/certified-then-signed.pdf");

printf(
    "\ncertified-then-signed.pdf: %d signatures, valid=%s, certified=%s, level=%s\n",
    $report->count(),
    $report->isValid() ? 'true' : 'false',
    $report->isCertified() ? 'true' : 'false',
    $report->certification?->value ?? '-',
);
