<?php

/**
 * Signs the cross-reference stream fixture, so the result can be checked in a
 * reader rather than only in the suite.
 *
 * This is the measurement docs/decisions/0009-cross-reference-streams.md turns
 * on. The previous attempt produced a file the suite accepted and poppler
 * reported as carrying no signatures, so "the bytes came out" is not the
 * question; "does pdfsig see a signature" is.
 *
 *   docker compose -f .docker/compose.yaml run --rm php php poc/sign-xref-stream.php
 *   pdfsig .output/xref-signed.pdf
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
file_put_contents("{$output}/xref-certificate.pfx", $pfx);

$signed = $app->make(A1PdfSign::class)->newSignature()
    ->certificate("{$output}/xref-certificate.pfx", $password)
    ->pdf(__DIR__ . '/../tests/Resources/xref-stream.pdf')
    ->info(name: 'Lucas Nepomuceno', reason: 'Cross-reference stream', location: 'Brazil')
    ->sign();

file_put_contents("{$output}/xref-signed.pdf", $signed->contents);

echo 'SIGNED: ' . strlen($signed->contents) . " bytes\n";

// Signing twice proves the appended stream is itself readable: the second
// revision has to find the first one's objects through it.
$twice = $app->make(A1PdfSign::class)->newSignature()
    ->certificate("{$output}/xref-certificate.pfx", $password)
    ->pdf("{$output}/xref-signed.pdf")
    ->info(name: 'Second Signer', reason: 'Second revision', location: 'Brazil')
    ->sign();

file_put_contents("{$output}/xref-signed-twice.pdf", $twice->contents);

echo 'SIGNED TWICE: ' . strlen($twice->contents) . " bytes\n";

// B-LTA exercises the other writing path. The Document Security Store and the
// archive timestamp are appended through appendObjects(), which closes each of
// its revisions with a cross-reference section of its own.
$app->make('config')->set('a1-pdf-sign.signature.timestamp.url', 'https://freetsa.org/tsr');

$archived = $app->make(A1PdfSign::class)->newSignature()
    ->certificate("{$output}/xref-certificate.pfx", $password)
    ->pdf(__DIR__ . '/../tests/Resources/xref-stream.pdf')
    ->info(name: 'Lucas Nepomuceno', reason: 'Long term', location: 'Brazil')
    ->profile(SignatureProfile::PadesBLTA)
    ->sign();

file_put_contents("{$output}/xref-signed-lta.pdf", $archived->contents);

echo 'SIGNED B-LTA: ' . strlen($archived->contents) . " bytes\n";
