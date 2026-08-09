<?php

/**
 * Signs into the fields a template already carries, so the result can be
 * checked in a reader rather than only in the suite.
 *
 * The question the suite cannot answer is whether a reader agrees the template's
 * own field was filled, as opposed to a second field having been appended beside
 * it (docs/decisions/0013-signing-into-an-existing-field.md).
 *
 *   docker compose -f .docker/compose.yaml run --rm php php poc/sign-into-field.php
 *   pdfsig .output/into-field.pdf
 */

use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
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
file_put_contents("{$output}/field-certificate.pfx", $pfx);

$manager = $app->make(A1PdfSign::class);
$template = __DIR__ . '/../tests/Resources/signature-fields.pdf';

foreach ($manager->signatureFields($template) as $field) {
    printf(
        "field %-20s signed=%-5s page=%d rect=[%s]\n",
        $field->name,
        $field->isSigned ? 'true' : 'false',
        $field->pageNumber,
        implode(' ', $field->rectangle),
    );
}

$target = "{$output}/into-field.pdf";
copy($template, $target);

// The second field first, deliberately. Filling them out of order is what
// catches a writer that fills "the next empty one" rather than the one named.
foreach (['SignatureEmployee' => 'Employee', 'SignatureManager' => 'Manager'] as $field => $who) {
    $signed = $manager->newSignature()
        ->certificate("{$output}/field-certificate.pfx", $password)
        ->pdf($target)
        ->intoField($field)
        ->info(name: $who, reason: "Signed as {$who}", location: 'Brazil')
        ->seal()
        ->profile(SignatureProfile::PadesBB)
        ->sign();

    $signed->save($target);

    printf("%-20s %8d bytes\n", $field, $signed->size());
}

$report = $manager->validate($target);

printf(
    "\nvalidate(into-field.pdf): %d signatures, valid=%s\n",
    $report->count(),
    $report->isValid() ? 'true' : 'false',
);

// The count is the assertion that matters. A template with two fields signed
// twice must still carry exactly two: a third means a field was appended
// beside the one asked for, which is the failure this feature exists to stop.
printf("signature fields now: %d\n", count($manager->signatureFields($target)));

foreach ($manager->signatureFields($target) as $field) {
    printf("field %-20s signed=%s\n", $field->name, $field->isSigned ? 'true' : 'false');
}
