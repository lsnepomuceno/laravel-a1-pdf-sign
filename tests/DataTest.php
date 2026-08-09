<?php

use LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SecurityStore;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureDetails;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Data\Signer;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;

it('returns a Certificate from the reader', function () {
    [$pfx, $password] = DebugCertificate::make();

    expect(app(NativeCertificateReader::class)->read($pfx, $password))
        ->toBeInstanceOf(Certificate::class);
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

    expect($report->toArray())->toBe(['signatures' => [], 'securityStore' => null])
        ->and($report->isValid())->toBeFalse()
        ->and($report->isSigned())->toBeFalse();
});

it('falls back to the unordered set when no chain could be built', function () {
    // signer() prefers the ordered chain, and a CMS whose certificates could not
    // be chained still has a signer worth reporting.
    $signer = new Signer('Only one', null, null, null, null, null, null);

    $withoutChain = new SignatureDetails(
        verified: true,
        signers: [$signer],
        coverageEnd: 10,
        coversWholeDocument: true,
    );

    expect($withoutChain->signer())->toBe($signer);

    // And with a chain, the chain wins: the first CMS entry is not necessarily
    // the leaf, which is the assumption the chain exists to replace.
    $leaf = new Signer('The leaf', null, null, null, null, null, null);

    $withChain = new SignatureDetails(
        verified: true,
        signers: [$signer],
        coverageEnd: 10,
        coversWholeDocument: true,
        chain: [$leaf],
    );

    expect($withChain->signer())->toBe($leaf);
});

it('has no security store key without the signature bytes', function () {
    // The key is the sha1 of /Contents, so a report built without them cannot
    // be matched against /VRI, and says so rather than inventing a key.
    $blind = new SignatureDetails(true, [], 10, true);

    expect($blind->securityStoreKey())->toBeNull();

    $known = new SignatureDetails(true, [], 10, true, false, null, null, 'the raw cms');

    expect($known->securityStoreKey())->toBe(strtoupper(sha1('the raw cms')));
});

it('reports no long-term material when the store covers another signature', function () {
    // A store can carry material for one signature in a document holding two.
    $covered = new SignatureDetails(true, [], 10, true, false, null, null, 'first');
    $uncovered = new SignatureDetails(true, [], 20, true, false, null, null, 'second');

    $store = new SecurityStore(
        certificates: 1,
        ocspResponses: 0,
        crls: 0,
        signatureKeys: [strtoupper(sha1('first'))],
    );

    expect((new SignatureReport([$covered], $store))->hasLongTermMaterial())->toBeTrue()
        ->and((new SignatureReport([$covered, $uncovered], $store))->hasLongTermMaterial())->toBeFalse()
        ->and((new SignatureReport([$covered], null))->hasLongTermMaterial())->toBeFalse()
        ->and($store->covers($uncovered))->toBeFalse();
});
