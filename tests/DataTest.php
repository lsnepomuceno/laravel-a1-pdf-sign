<?php

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

it('returns a Certificate from ManageCert', function () {
    $cert = new ManageCert();
    $cert->makeDebugCertificate();

    expect($cert->getCert())->toBeInstanceOf(Certificate::class);
});

it('reports the certificate expiry from the parsed data', function () {
    $future = time() + 3600;
    $past = time() - 3600;

    expect((new Certificate('pem', false, ['validTo_time_t' => $future], ''))->expiresAt())->toBe($future)
        ->and((new Certificate('pem', false, ['validTo_time_t' => $future], ''))->isExpired())->toBeFalse()
        ->and((new Certificate('pem', false, ['validTo_time_t' => $past], ''))->isExpired())->toBeTrue();
});

it('treats a certificate without an expiry as not expired', function () {
    $cert = new Certificate('pem', false, [], '');

    expect($cert->expiresAt())->toBeNull()
        ->and($cert->isExpired())->toBeFalse();
});

it('reads the common name, falling back to the organisation', function () {
    expect((new Certificate('pem', false, ['subject' => ['commonName' => 'ACME']], ''))->commonName())
        ->toBe('ACME')
        ->and((new Certificate('pem', false, ['subject' => ['organizationName' => 'ACME Ltd']], ''))->commonName())
        ->toBe('ACME Ltd')
        ->and((new Certificate('pem', false, [], ''))->commonName())->toBeNull();
});

it('exposes its properties through toArray', function () {
    $report = new SignatureReport([]);

    expect($report->toArray())->toBe(['signatures' => []])
        ->and($report->isValid())->toBeFalse()
        ->and($report->isSigned())->toBeFalse();
});
