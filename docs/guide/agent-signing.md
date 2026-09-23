# Signing through an agent

`Ai\Tools\SignPdf` lets an AI SDK agent sign a document, **and never without a
person approving that exact call first**. A signature made with an ICP-Brasil
certificate has the legal weight of a handwritten one, so the tool is built
around refusals rather than conveniences
([0040](/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk)).

```bash
composer require laravel/ai
```

Then [open a disk](/guide/agents#opening-a-disk). The signing tool reads from
and writes to the same allowlist as the read tools.

## The four guarantees

| | How |
|---|---|
| **Every call waits for a person** | `shouldRequestApproval()` always asks, and `withoutApproval()` throws |
| **The certificate never passes through the model** | the key comes from a resolver you bind, and the tool's schema has no field for it |
| **A repeated call signs once** | the provider's tool-call id is claimed in your cache before anything is signed |
| **Only open disks, never over a file** | source and destination both go through the same guard as the read tools |

## Choosing the certificate

The model sends a document, a destination, a profile and a reason. It never
sends a certificate or a password. Which key signs is answered by
`Contracts\SigningCertificateResolver`, which you implement:

```php
use LSNepomuceno\LaravelA1PdfSign\Contracts\SigningCertificateResolver;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\Signet\Data\Certificate;

final readonly class AuthenticatedUserCertificate implements SigningCertificateResolver
{
    public function resolve(): Certificate
    {
        $stored = auth()->user()->signingCertificate;   // your model

        return A1PdfSign::decryptCertificate($stored->hash, $stored->certificate, $stored->password);
    }
}
```

`decryptCertificate()` is the vault described in
[certificates](/guide/certificates#storing-one). Bind the resolver once:

```php
// AppServiceProvider::register()
$this->app->bind(SigningCertificateResolver::class, AuthenticatedUserCertificate::class);
```

Unbound, the tool raises `SigningCertificateUnavailable` naming the contract to
bind. That is a wiring mistake, so it is thrown for you to see rather than
handed to the model.

### An agent running on a queue

`auth()` answers nothing inside a queued job. Pass the resolver to the tool
instead, carrying the user it signs for:

```php
final readonly class CertificateOf implements SigningCertificateResolver
{
    public function __construct(private User $user) {}

    public function resolve(): Certificate
    {
        $stored = $this->user->signingCertificate;

        return A1PdfSign::decryptCertificate($stored->hash, $stored->certificate, $stored->password);
    }
}

new SignPdf(new CertificateOf($user));
```

The resolver is called twice per signature: once to describe the certificate to
the person approving, once to sign after they do.

## Giving it to an agent

```php
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\{Agent, Conversational, HasTools};
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\McpServerTool;
use LSNepomuceno\LaravelA1PdfSign\Ai\Tools\SignPdf;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\ValidatePdfSignature;

final class ContractAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'You help the user review and sign the company\'s contracts.';
    }

    public function tools(): iterable
    {
        return [
            new McpServerTool(new ValidatePdfSignature()),
            new SignPdf(),
        ];
    }
}
```

**The agent has to be resumable.** The SDK pauses a run for approval only when
it can pick it up again, which means `Conversational`, usually with
`RemembersConversations` so the paused turn is stored. Without it, the SDK
raises `ApprovalNotResumableException` at the first signature, which is the
correct failure: a run that cannot wait for a person must not sign.

## The approval flow

### 1. The run pauses

```php
$response = new ContractAssistant()
    ->forUser($request->user())
    ->prompt('Sign the Acme contract.');

$response->hasPendingApprovals();   // true
```

Nothing has been signed, written or announced. The run stopped at the question.

### 2. You show the person what would happen

```php
foreach ($response->pendingApprovals as $approval) {
    $approval->id;          // 'call_abc', the provider's id for this call
    $approval->tool;        // 'sign_pdf'
    $approval->arguments;   // what the model asked for
    $approval->reason;      // what to show, below
}
```

`reason` is written by the tool for a person to read, and names everything that
matters:

```
Sign [contracts/2026/acme.pdf] on the disk [contracts] with the certificate of MARIA DA SILVA (111.444.777-35), valid until 2027-03-01.
Profile: pades-b-t.
Reason recorded in the signature: "Contract approval".
The signed copy is written to [contracts/2026/acme_signed.pdf] on the disk [contracts].
```

A certificate past its date says `which EXPIRED on …`, and one the resolver
could not open says so and that signing will fail, so the person sees it coming
rather than approving a call that is going to break.

This text goes to your application and to the person, not to the model, which
is why it carries the CPF even when `agents.expose_registry` is off: the person
approving is normally the certificate's holder, and a CPF is how they tell two
certificates with the same name apart.

### 3. The run resumes with their decision

```php
use Laravel\Ai\Approvals\{Decision, Decisions};

$response = new ContractAssistant()
    ->continue($conversationId, as: $request->user())
    ->prompt(Decisions::from([
        'call_abc' => Decision::approve(),
        // or: Decision::reject('Not until legal reviews it.'),
    ]));
```

On approval the tool signs, writes the copy and returns a result the model
reads:

```json
{
  "signed": true,
  "status": "signed",
  "disk": "contracts",
  "path": "contracts/2026/acme_signed.pdf",
  "source": { "disk": "contracts", "path": "contracts/2026/acme.pdf" },
  "signer": "MARIA DA SILVA",
  "profile": "pades-b-t",
  "size": 48213,
  "revision_size": 21877,
  "sha256": "9f2c…"
}
```

### Editing a call before approving it

The SDK also offers `Decision::edit($arguments)`, which resumes with arguments
the person changed, **and runs them without asking again**. The tool validates
the edited call through the same guard as any other, so it cannot reach a
closed disk or overwrite a file. But the text the person approved described the
original call. If your interface offers editing, show the edited call and ask
again before resuming, or do not offer it for `sign_pdf`.

## Adding your own words to the question

```php
new SignPdf()->requireApproval('Requested by the procurement workflow.');
```

The note goes above the description; it never replaces it. This is the only
thing `requireApproval()` does here. `withoutApproval()` throws a
`LogicException`:

```
SignPdf cannot run without approval: every signature it makes needs a person to approve it.
Sign in your own code, through the facade's newSignature(), when no person is involved.
```

If nobody is going to approve, there is no agent in the decision, and signing
belongs in your own code with the [builder](/guide/signing).

## What the model can ask for

| Argument | Required | Notes |
|---|---|---|
| `disk` | yes | one of `agents.disks`, offered as an enum |
| `path` | yes | relative to the disk, ending in `.pdf` |
| `destination_disk` | no | another open disk. Defaults to `disk` |
| `destination_path` | no | defaults to the original with `_signed` before the extension. Must not exist |
| `profile` | no | `legacy`, `pades-b-b`, `pades-b-t`, `pades-b-lt`, `pades-b-lta`. Defaults to [the configured one](/guide/configuration#signing) |
| `reason` | no | recorded inside the signature, 255 characters at most |

A profile above `pades-b-b` needs a timestamp authority configured, exactly as
it does outside an agent.

A malformed call, a missing `path` or an unknown profile, is returned to the
model by the SDK as a validation message, so it can correct itself. A refusal
comes back as a result:

```json
{ "signed": false, "status": "refused", "message": "A file already exists at [acme_signed.pdf] on the disk [contracts], and a signed document never overwrites one. Choose another destination path." }
```

## A repeated call signs once

After a person approves, the same call can still arrive twice: a provider
retrying on a timeout, a queued run picked up again, a double click on
"approve". Every repetition carries the provider's id for the call, and the
tool claims that id in your cache before signing:

| The id is | The tool |
|---|---|
| new | claims it, signs, stores the result |
| claimed, and finished | returns the first call's result. Nothing is signed |
| claimed, and still running | returns `"status": "in_progress"`. Nothing is signed |
| claimed by a call that failed | was released, so the retry signs |

Claims last a day by default. **The claim is exactly as atomic as the cache
store's `add()`**: Redis, Memcached, DynamoDB and the database store are
atomic, the file and array stores are not. Pick the store for this:

```php
'agents' => [
    'idempotency' => [
        'store' => env('A1_PDF_SIGN_AGENTS_CACHE_STORE'),   // null: the default store
        'ttl' => 86400,
    ],
],
```

## After it signs

`Events\DocumentSignedByAgent` is dispatched once the copy is written, and
never for a refused call or a repetition:

```php
use LSNepomuceno\LaravelA1PdfSign\Events\DocumentSignedByAgent;

Event::listen(function (DocumentSignedByAgent $event) {
    SignatureAudit::create([
        'user_id' => $event->userId,
        'tool_call_id' => $event->toolCallId,
        'source' => "{$event->sourceDisk}:{$event->sourcePath}",
        'signed' => "{$event->disk}:{$event->path}",
        'profile' => $event->receipt?->profile?->value,
        'sha256' => $event->receipt?->hash,
    ]);
});
```

A listener that throws fails the tool call, but only after the copy is written
and the call recorded, so a retry gets the recorded result rather than a second
signature. Queue a listener that can fail.

The receipt is signet's `SigningReceipt`: what signing knew and two digests,
with no PDF and no secret in it, so the event is safe to store and to queue.
`userId` comes from the default guard, and is null when nobody was signed in.

## What it cannot see

Approval is the agent loop's to enforce, and the tool makes sure the loop
always asks. What it cannot see is code that calls `handle()` directly, or
wraps the tool in something that is not `Approvable`. Neither happens by
accident, and both are a decision to sign without a person, which belongs in
your own code, in plain sight.
