<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Validation\ChainBuilder;

/**
 * A real two-level chain: a CA that signs a leaf. The samples cannot exercise
 * this, since their certificate is self-signed and its chain is trivially one
 * entry long.
 *
 * @return array{0: string, 1: string} Leaf PEM, then CA PEM.
 */
function twoLevelChain(): array
{
    $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'digest_alg' => 'sha256']);
    assert($caKey instanceof OpenSSLAsymmetricKey);
    /** @var OpenSSLAsymmetricKey $caKey */

    $caCsr = openssl_csr_new(['commonName' => 'Test Root CA', 'countryName' => 'BR'], $caKey, ['digest_alg' => 'sha256']);
    assert($caCsr instanceof OpenSSLCertificateSigningRequest);

    $ca = openssl_csr_sign($caCsr, null, $caKey, 800, ['digest_alg' => 'sha256']);
    assert($ca instanceof OpenSSLCertificate);

    $leafKey = openssl_pkey_new(['private_key_bits' => 2048, 'digest_alg' => 'sha256']);
    assert($leafKey instanceof OpenSSLAsymmetricKey);
    /** @var OpenSSLAsymmetricKey $leafKey */

    $leafCsr = openssl_csr_new(['commonName' => 'Test Leaf', 'countryName' => 'BR'], $leafKey, ['digest_alg' => 'sha256']);
    assert($leafCsr instanceof OpenSSLCertificateSigningRequest);

    $leaf = openssl_csr_sign($leafCsr, $ca, $caKey, 400, ['digest_alg' => 'sha256']);
    assert($leaf instanceof OpenSSLCertificate);

    openssl_x509_export($leaf, $leafPem);
    openssl_x509_export($ca, $caPem);

    assert(is_string($leafPem) && is_string($caPem));

    return [$leafPem, $caPem];
}

it('orders a chain leaf first, whatever order it arrives in', function () {
    [$leaf, $ca] = twoLevelChain();
    $builder = new ChainBuilder();

    // The CMS carries certificates as a set, so both orders must give the same
    // chain. This is the assumption signer() used to make and could not check.
    expect($builder->build([$leaf, $ca]))->toBe([$leaf, $ca])
        ->and($builder->build([$ca, $leaf]))->toBe([$leaf, $ca]);
});

it('confirms each link with the issuer key rather than by name', function () {
    [$leaf] = twoLevelChain();
    [, $unrelatedCa] = twoLevelChain();

    // A second CA with the same subject name did not sign this leaf, so it must
    // not be chained to it. Matching names alone would accept it.
    $chain = new ChainBuilder()->build([$leaf, $unrelatedCa]);

    expect($chain)->toHaveCount(1)
        ->and($chain[0])->toBe($leaf);
});

it('says whether the chain reaches a self-signed root', function () {
    [$leaf, $ca] = twoLevelChain();
    $builder = new ChainBuilder();

    expect($builder->reachesRoot($builder->build([$leaf, $ca])))->toBeTrue()
        ->and($builder->reachesRoot($builder->build([$leaf])))->toBeFalse();
});

it('builds the chain of a real signed document', function () {
    // The sample certificate is self-signed, so its chain is one entry that is
    // also the root. Trivial, and worth pinning: it is the shape most documents
    // signed with an A1 certificate in testing will have.
    $report = A1PdfSign::validate(__DIR__ . '/../samples/pades-b-b.pdf');
    $signature = $report->signatures[0];

    expect($signature->chain)->toHaveCount(1)
        ->and($signature->chainReachesRoot)->toBeTrue()
        ->and($signature->signer()?->commonName)->toBe('Test Certificate');
});

it('returns nothing for an empty pool', function () {
    expect(new ChainBuilder()->build([]))->toBe([])
        ->and(new ChainBuilder()->reachesRoot([]))->toBeFalse();
});

it('walks a chain more than one link long', function () {
    // Two levels prove ordering; three prove the loop keeps going rather than
    // stopping at the first issuer it finds.
    $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'digest_alg' => 'sha256']);
    assert($rootKey instanceof OpenSSLAsymmetricKey);
    /** @var OpenSSLAsymmetricKey $rootKey */
    $rootCsr = openssl_csr_new(['commonName' => 'Root', 'countryName' => 'BR'], $rootKey, ['digest_alg' => 'sha256']);
    assert($rootCsr instanceof OpenSSLCertificateSigningRequest);
    $root = openssl_csr_sign($rootCsr, null, $rootKey, 900, ['digest_alg' => 'sha256']);
    assert($root instanceof OpenSSLCertificate);

    $midKey = openssl_pkey_new(['private_key_bits' => 2048, 'digest_alg' => 'sha256']);
    assert($midKey instanceof OpenSSLAsymmetricKey);
    /** @var OpenSSLAsymmetricKey $midKey */
    $midCsr = openssl_csr_new(['commonName' => 'Intermediate', 'countryName' => 'BR'], $midKey, ['digest_alg' => 'sha256']);
    assert($midCsr instanceof OpenSSLCertificateSigningRequest);
    $mid = openssl_csr_sign($midCsr, $root, $rootKey, 800, ['digest_alg' => 'sha256']);
    assert($mid instanceof OpenSSLCertificate);

    $leafKey = openssl_pkey_new(['private_key_bits' => 2048, 'digest_alg' => 'sha256']);
    assert($leafKey instanceof OpenSSLAsymmetricKey);
    /** @var OpenSSLAsymmetricKey $leafKey */
    $leafCsr = openssl_csr_new(['commonName' => 'Leaf', 'countryName' => 'BR'], $leafKey, ['digest_alg' => 'sha256']);
    assert($leafCsr instanceof OpenSSLCertificateSigningRequest);
    $leaf = openssl_csr_sign($leafCsr, $mid, $midKey, 400, ['digest_alg' => 'sha256']);
    assert($leaf instanceof OpenSSLCertificate);

    openssl_x509_export($leaf, $leafPem);
    openssl_x509_export($mid, $midPem);
    openssl_x509_export($root, $rootPem);
    assert(is_string($leafPem) && is_string($midPem) && is_string($rootPem));

    $builder = new ChainBuilder();
    $chain = $builder->build([$rootPem, $leafPem, $midPem]);

    expect($chain)->toBe([$leafPem, $midPem, $rootPem])
        ->and($builder->reachesRoot($chain))->toBeTrue();
});

it('treats a lone self-signed certificate as its own root', function () {
    [, $ca] = twoLevelChain();
    $builder = new ChainBuilder();

    expect($builder->build([$ca]))->toBe([$ca])
        ->and($builder->reachesRoot([$ca]))->toBeTrue();
});

it('ignores certificates that belong to no link', function () {
    [$leaf, $ca] = twoLevelChain();
    [$strayLeaf] = twoLevelChain();

    // A pool can carry certificates for another signature entirely. They must
    // not extend this chain.
    $chain = new ChainBuilder()->build([$leaf, $ca, $strayLeaf]);

    expect($chain)->toBe([$leaf, $ca]);
});

it('answers nothing for input that is not a certificate', function () {
    $builder = new ChainBuilder();

    expect($builder->build(['not a certificate']))->toBe(['not a certificate'])
        ->and($builder->reachesRoot(['not a certificate']))->toBeFalse();
});
