# Auditing

Digital signatures in corporate systems are audited: who signed, when, at what level, and which documents failed to verify are questions somebody eventually has to answer.

#### 1 - Off by default. <small>(since 2.6)</small>

A package that logs unasked fills somebody's disk, so nothing is recorded until you bind a logger:

```PHP
use LSNepomuceno\LaravelA1PdfSign\Support\SigningLog;
use Illuminate\Support\Facades\Log;

// in a service provider
$this->app->bind(SigningLog::class, fn () => new SigningLog(Log::channel('audit')));
```

Any PSR-3 logger works. It is injected rather than resolved from a facade inside the signer, because a dependency reached through a facade is invisible and untestable.

#### 2 - The events.

```PHP
enum SigningEvent: string
{
    case SignatureApplied     = 'signature.applied';
    case TimestampReceived    = 'timestamp.received';
    case ValidationCompleted  = 'validation.completed';
    case ValidationFailed     = 'validation.failed';
}
```

Four, not one per internal step. **An audit trail and a debug trace want different retention**, and mixing them produces neither.

A line looks like this:

```
signature.applied  {event: "signature.applied", profile: "pades-b-lt", field: "Signature1",
                    certification: null, signer: "ACME LTDA:11222333000181"}
```

#### 3 - What can never appear in a line.

This package handles PKCS#12 bundles, private keys and passwords. Every password argument is marked `#[\SensitiveParameter]`, which keeps the value out of a **stack trace** and has nothing to say about a line written to disk. A logger is a second channel, and it has its own answer.

**The context is an allowlist**, not a denylist:

```PHP
SigningLog::ALLOWED;   // event, profile, field, certification, signer, serial,
                       // signed_at, authority, valid, signatures, exception
```

Anything else is dropped, and values must be scalars, so an object cannot arrive under a permitted name and be serialised by whatever formats the line.

> A denylist is how the next property added to a data object ends up in a log file: silently, because nobody remembered to forbid it.

Absent on purpose, although they would be useful: the document, the CMS, the PFX bytes, any password, and **any file path**. A path is enough to find the bundle it names.

#### 4 - Before you enable it on a public endpoint.

`ValidationFailed` is the event most worth auditing and also the one an attacker can trigger in bulk, by feeding your application bad documents. If validation is publicly reachable, rate limit before you turn this on.
