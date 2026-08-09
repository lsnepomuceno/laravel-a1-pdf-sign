<?php

/**
 * The differential test for /DocMDP enforcement.
 *
 * Certifies one document twice, at "no-changes" and at "form-filling", so the
 * two differ in nothing but the permission. Open both in a reader and try to
 * type in the text field: /P 1 forbids it and /P 2 permits it, so a reader that
 * enforces the transform behaves differently on the two files and a reader that
 * ignores it behaves identically.
 *
 * **The fixture has to carry a real form field.** The first attempt used
 * tests/Resources/test.pdf, which has none: the only widget was the signature's
 * own, and clicking a signature field shows the certificate at every level
 * regardless of any certification. Identical behaviour there was not evidence,
 * it was the absence of anything to observe.
 *
 * Measured on 2026-08-09: poppler, through Okular, allows typing in both. It
 * does not enforce /DocMDP. See docs/decisions/0012-certification-signatures.md.
 *
 *   docker compose -f .docker/compose.yaml run --rm php php poc/certify-fillable.php
 *   okular .output/fillable-no-changes.pdf .output/fillable-form-filling.pdf
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
file_put_contents("{$output}/fillable.pfx", $pfx);

$manager = $app->make(A1PdfSign::class);

foreach ([CertificationLevel::NoChanges, CertificationLevel::FormFilling] as $level) {
    $certified = $manager->newSignature()
        ->certificate("{$output}/fillable.pfx", $password)
        ->pdf(__DIR__ . '/../tests/Resources/fillable.pdf')
        ->certify($level)
        ->info(name: 'Author', reason: 'Certified as ' . $level->value)
        ->sign();

    $certified->save("{$output}/fillable-{$level->value}.pdf");

    printf("%-14s %6d bytes  /P %d\n", $level->value, $certified->size(), $level->permission());
}

echo "\nOpen both and type in the grey box, not on the signature.\n";
echo "Different behaviour means the reader enforces /DocMDP; identical means it does not.\n";
