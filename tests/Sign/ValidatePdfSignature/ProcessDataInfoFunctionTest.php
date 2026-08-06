<?php

use LSNepomuceno\LaravelA1PdfSign\Sign\ValidatePdfSignature;

/**
 * Fixtures are real SAT (México) certificate dumps, kept as the specification
 * for the parser. See ARCHITECTURE-V2.md §3b — the text parsing they exercise
 * is replaced by openssl_x509_parse() in PR 9.
 */
dataset('certificate info dumps', function () {
    foreach (['with-comma', 'without-comma'] as $case) {
        yield $case => [
            json_decode(file_get_contents(resource("CertInfoExamples/{$case}.json")), true),
            trim(file_get_contents(resource("CertInfoExamples/{$case}.txt"))),
        ];
    }
});

it('parses certificate info into key/value pairs', function (array $expected, string $content) {
    $method = new ReflectionMethod(ValidatePdfSignature::class, 'processDataToInfo');
    $method->setAccessible(true);

    expect($method->invoke(new ValidatePdfSignature(), $content))->toBe($expected);
})->with('certificate info dumps');
