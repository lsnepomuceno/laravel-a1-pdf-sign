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
└── Testing/A1PdfSignFake.php
```

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

## Commands

`pdf:sign`, `pdf:validate-signature`, `pdf:fields`, `pdf:add-field`,
`pdf:extend`, `a1-pdf-sign:check`. Their names and exit codes are public: a
pipeline calls them.

## What is not public

- Anything under `LSNepomuceno\Signet\`, which is signet-pdf's to promise
- `Commands\Concerns\ReadsTypedInput`, an internal convenience
- The private methods of the manager
