# Public API

What this package exposes, as it is built. Everything here is a promise to
consumers: adding to it is a minor release, changing it is a major one.

**Most of what a consumer touches is signet-pdf's.** Every `Data`, `Enums`,
`Exceptions`, `Validation`, `Signing`, `Certificates`, `Seal` and `Support`
class is documented in
[signet-pdf's public API](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/spec/public-api.md),
and changing one of them is that package's release to make
([0039](../decisions/0039-the-core-lives-in-signet-pdf.md)).

This file covers what belongs to the wrapper, which is what can be broken here.

## Namespace layout

```
src/
├── LaravelA1PdfSignServiceProvider.php   # assembles Signet, binds its contracts
├── A1PdfSignManager.php                  # the A1PdfSign implementation
├── Facades/A1PdfSign.php
├── Contracts/A1PdfSign.php               # this package's own surface
├── Adapters/                             # Laravel behind signet's contracts
├── Config/SignetConfigFactory.php
├── Io/                                   # disks and uploads as sources
├── Commands/                             # six artisan commands
├── Testing/A1PdfSignFake.php
├── Contracts/SigningCertificateResolver.php   # which certificate an agent signs with
├── Agents/                               # the path guard and ledger both SDKs share
├── Mcp/                                  # needs laravel/mcp: the read tools and their server
├── Ai/                                   # needs laravel/ai: the signing tool
├── Events/DocumentSignedByAgent.php
└── Exceptions/                           # DocumentOutOfReach, SigningCertificateUnavailable
```

`Mcp/` and `Ai/` can only be loaded with their SDK installed, and nothing
outside them names a class inside them. That is what lets both SDKs stay
optional ([0040](../decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md)).

The root namespace `LSNepomuceno\LaravelA1PdfSign` is fixed; renaming it would
be a gratuitous break.

## The facade, and the contract behind it

`Contracts\A1PdfSign`, resolved from the container or reached through the
`A1PdfSign` facade. Every method either delegates to `Signet\Signet` or does
something only a Laravel application can ask for.

| Method | Returns | Notes |
|---|---|---|
| `newSignature()` | `Signing\PendingSignature` | signet's builder, unchanged |
| `signFromFile($pfx, $password, $pdf, ?$usePathEnv)` | `Data\SignedPdf` | |
| `signFromPem($pem, $password, $pdf, ?$keyPath)` | `Data\SignedPdf` | |
| `signFromUpload($upload, $password, $pdf, ?$usePathEnv)` | `Data\SignedPdf` | **Laravel only**: takes `UploadedFile` |
| `encryptCertificate($uploadOrPath, $password, ?$usePathEnv)` | `Data\EncryptedCertificate` | accepts an upload, which is why it does not delegate |
| `decryptCertificate($hash, $material, $password, $isBase64, ?$usePathEnv)` | `Data\Certificate` | the third argument is the **sealed** password |
| `validate($pdf, ?$trust, $documentPassword)` | `Data\SignatureReport` | takes a path or a `PdfSource` |
| `signatureFields($pdf)` | `list<Data\SignatureField>` | as above |
| `extendArchive($pdf, $documentPassword)` | `Data\SignedPdf` | as above |
| `complete($prepared, $cms, ?$certificate, $documentPassword)` | `Data\SignedPdf` | two-phase signing, the key stays outside |
| `addSignatureField($pdf, $name, ?$placement, $documentPassword)` | `Data\SignedPdf` | null placement leaves it invisible |
| `icpBrasil($pfx, $password)` | `IcpBrasil\Data\Report` | |
| `fromDisk($disk, $path)` | `Contracts\PdfSource` | **Laravel only** |
| `fromUpload($file)` | `Contracts\PdfSource` | **Laravel only** |
| `toDisk($disk, ?$path)` | `Contracts\PdfDestination` | **Laravel only**, null path keeps the document's name |
| `tempPath($tempFile, $ext)` | `string` | honours `a1-pdf-sign.temp_path`, creates the directory |

**Nullable arguments mean "use the configured default"**, rather than forcing
every call site to repeat an infrastructure decision.

`tests/Project/ArchTest.php` fails when a method here is missing from the
README, and when the facade's `@method` docblock disagrees with the contract.

## The adapters

Public because an application may want to bind its own, and because they are
what the package is for. Each implements a signet contract:

| Class | Contract |
|---|---|
| `Adapters\IlluminateProcessRunner` | `Signet\Contracts\ProcessRunner` |
| `Adapters\IlluminateEncrypter` | `Signet\Contracts\Encrypter` |
| `Adapters\IlluminateSignatureTransport` | `Signet\Contracts\SignatureTransport` |
| `Io\DiskSource`, `Io\UploadedFileSource` | `Signet\Contracts\PdfSource` |
| `Io\DiskDestination` | `Signet\Contracts\PdfDestination` |

Replacing one is a matter of binding it in a service provider. **Replacing the
first two with signet's own defaults breaks `Process::fake()` and
`Http::fake()`**, which invariant 1 exists to prevent.

## What the container binds

`Signet\Signet` as a singleton, assembled from the config file with the
adapters injected, plus signet's contracts as accessors on it:
`PdfSigner`, `SignatureValidator`, `SignatureVerifier`, `SealRenderer`,
`CertificateReader`, `SignatureTransport`, `ProcessRunner`.

They are accessors rather than separate bindings on purpose: replacing the
engine replaces everything, which is what makes `A1PdfSign::fake()` work at
all.

## Configuration

`config/a1-pdf-sign.php`, publishable with the `a1-pdf-sign-config` tag. Every
key is a scalar, and `Config\SignetConfigFactory` turns them into
`Signet\Config\SignetConfig` at resolution.

```
temp_path
signature.profile, signature.digest_algorithm, signature.policy
signature.timestamp.{url,username,password,timeout,attempts,backoff}
signature.ltv.{timeout,attempts,backoff}
certificate.{legacy,use_path_env,chain_paths}
seal.{driver,transparent,background,text.x,text.rows,font.{path,size,color}}
agents.{disks,max_bytes,expose_registry}
agents.idempotency.{store,ttl}
```

Adding a key is a minor release. Removing or renaming one is a major release,
because an application's published config file will keep the old name.

## Testing

`A1PdfSign::fake()` returns `Testing\A1PdfSignFake`, which records rather than
signs. Its assertions are `assertSigned()`, `assertSignedTimes()`,
`assertNothingSigned()`, `assertSignedWithProfile()`, `assertCertified()`,
`assertSealed()`, `assertPrepared()` and `assertCompleted()`.

`Testing\A1PdfSignFake::certificate()` hands back a certificate that opens
nothing, for the builder's guard.

## Agent tools

Optional: each needs its SDK, and both SDKs are `suggest`ed, pinned to `^1.0`
by `conflict`
([0040](../decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md)).

| Class | Needs | Tool name | Writes |
|---|---|---|---|
| `Mcp\Tools\ValidatePdfSignature` | `laravel/mcp` | `validate_pdf_signature` | nothing |
| `Mcp\Tools\ListSignatureFields` | `laravel/mcp` | `list_signature_fields` | nothing |
| `Mcp\A1PdfSignServer` | `laravel/mcp` | the two above, as a server | nothing |
| `Ai\Tools\SignPdf` | `laravel/ai` | `sign_pdf` | a signed copy, after approval |

**The tool names, their arguments, the keys of what they return and the
ability names and their arguments are public API**, since a prompt, a client or an application's own code depends on them.
Adding an optional argument or a key is a minor release; renaming or removing
one is a major one.

What else a consumer touches:

| | |
|---|---|
| `Contracts\SigningCertificateResolver` | `resolve(): Certificate`. The application binds it, or passes it to `new SignPdf(...)` |
| `Events\DocumentSignedByAgent` | `sourceDisk`, `sourcePath`, `disk`, `path`, `toolCallId`, `userId`, `receipt` |
| `Exceptions\DocumentOutOfReach` | a disk, path or destination refused. Implements `SignetException` |
| `Exceptions\SigningCertificateUnavailable` | no resolver bound. A `LogicException`, implements `SignetException` |
| `Agents\DocumentAccess` | the guard, public so an application's own tools can use it: `source()`, `destination()`, `authorize()`, `allows()`, `disks()`, `maxBytes()`, `exposesRegistry()` |
| `Agents\Ability` | `Read` = `a1-pdf-sign.agents.read`, `Sign` = `a1-pdf-sign.agents.sign`. The gate abilities the tools ask, when the application defines them ([0041](../decisions/0041-agents-are-authorised-per-document.md)) |

`SignPdf::withoutApproval()` throws, and that is part of the contract rather
than a limitation to be lifted: a release that let it succeed would be a
breaking change in what the tool promises.

`Mcp\A1PdfSignServer` is not final, so an application can extend it and append
its own tools. Its `$tools` list growing is a minor release.

## Commands

`pdf:sign`, `pdf:validate-signature`, `pdf:fields`, `pdf:add-field`,
`pdf:extend`, `a1-pdf-sign:check`. Their names and exit codes are public: a
pipeline calls them.

## What is not public

- Anything under `LSNepomuceno\Signet\`, which is signet-pdf's to promise
- `Agents\Arguments` and `Agents\ToolCallLedger`, internal to the tools
- The wording of a tool's description or of an approval's text, which may be
  improved in any release. Their facts are stable; their sentences are not
- `Commands\Concerns\ReadsTypedInput`, an internal convenience
- The private methods of the manager
