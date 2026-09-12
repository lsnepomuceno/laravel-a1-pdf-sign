# Testing an application that signs

Your suite should not need a PKCS#12 bundle, and it should not build a real CMS
for a test that merely passes through the signing call.

```php
$signing = A1PdfSign::fake();

// … the application runs …

$signing->assertSigned();
$signing->assertSignedTimes(1);
$signing->assertSignedWithProfile(SignatureProfile::PadesBLT);
$signing->assertCertified(CertificationLevel::NoChanges);
$signing->assertSealed();
$signing->assertNothingSigned();
$signing->assertPrepared();
$signing->assertCompleted();
```

It replaces **the engine** in the container, not one binding. Replacing
`PdfSigner` alone would leave `newSignature()->…->sign()` reaching the real
signer, because the engine resolves its own.

The result is still a `SignedPdf`, so code calling `->contents`, `->size()` or
`->save()` keeps working, and `certificate()` accepts any path because nothing
is parsed.

## The other seams

The fake covers signing. Everything else is replaced with the framework's own
tools, and that is most of the reason this package exists rather than just
signet-pdf:

```php
Process::fake();               // the openssl shell-out
Http::fake();                  // the timestamp authority, OCSP and CRL
Http::preventStrayRequests();  // proves a pades-b-b signature reaches no network
Storage::fake('s3');           // signing from a disk and writing back to one
```

**`Process::fake()` working is an invariant**, not a convenience: it is why the
package binds its own process runner rather than letting the engine build one
inline ([invariants](/spec/invariants)).

## Signing for real, offline

For the profiles above `pades-b-b`, substitute the transport and they are gated
offline with real RFC 3161 tokens instead of being reported against a live
authority:

```php
app()->instance(
    SignatureTransport::class,
    new LocalTimestampAuthority(app(ProcessRunner::class)),
);
```

It needs the process runner because it signs its own tokens by shelling out to
`openssl ts -reply`, exactly as a real authority would.

`Signet\Testing\DebugCertificate::make()` generates a throwaway PKCS#12 bundle
through `ext-openssl`, so a real signature in a test needs no certificate in
your repository.
