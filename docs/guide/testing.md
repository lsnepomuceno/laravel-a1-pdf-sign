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

## Agents

The agent tools test with the SDKs' own fakes, and nothing here needs a model
or a key.

**Fake the model, keep the loop.** `YourAgent::fake()` replaces the provider,
not the AI SDK's loop, so a faked tool call still goes through approval, the
MCP wrapper and validation, exactly as in production:

```php
use Laravel\Ai\Responses\Data\ToolCall;

ContractAssistant::fake([
    new ToolCall('call_1', 'sign_pdf', ['disk' => 'contracts', 'path' => 'acme.pdf']),
]);

$response = new ContractAssistant()->prompt('Sign the Acme contract.');

expect($response->hasPendingApprovals())->toBeTrue()
    ->and($response->pendingApprovals->first()->reason)->toContain('Sign [acme.pdf]');

Storage::disk('contracts')->assertMissing('acme_signed.pdf');
```

The SDK does not run tools when a faked run is resumed with a decision, so the
"after approval" half is `handle()`, called the way the SDK calls it:

```php
use Laravel\Ai\Tools\Request;

Event::fake([DocumentSignedByAgent::class]);

new SignPdf(new CertificateOf($user))
    ->handle(new Request(['disk' => 'contracts', 'path' => 'acme.pdf'], 'call_1'));

Storage::disk('contracts')->assertExists('acme_signed.pdf');
Event::assertDispatched(DocumentSignedByAgent::class);
```

**MCP tools test through the server**, with `laravel/mcp`'s own helpers:

```php
A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => 'acme_signed.pdf'])
    ->assertOk()
    ->assertStructuredContent(fn ($json) => $json->where('valid', true)->etc());
```

`Storage::fake()` covers the disks and `A1PdfSign::fake()` still covers the
signature, since `SignPdf` signs through the facade's contract rather than
around it. Remember to open the faked disk in `a1-pdf-sign.agents.disks`.
