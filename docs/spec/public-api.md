# Public API

What this package exposes, as it is built. Everything here is a promise to
consumers: adding to it is a minor release, changing it is a major one.

> Written from the code, not from the v2 plan. The plan's §2 described a
> `TcLibPdfSigner` / `TcpdfSigner` pair, an `Enums\SealPage`, a `Console\`
> namespace and `approval()` / `certify()` / `ltv()` builder methods. None of
> them were built. See [the modernisation record](../history/v2-modernization.md).
> This file supersedes that section.

## Namespace layout

The root namespace `LSNepomuceno\LaravelA1PdfSign` is fixed; renaming it would
be a gratuitous break. Structure below it:

```
src/
├── LaravelA1PdfSignServiceProvider.php   # binds six contracts, registers two commands
├── A1PdfSignManager.php                  # the A1PdfSign implementation
├── Facades/A1PdfSign.php
├── Contracts/                            # A1PdfSign, CertificateReader, PdfSigner,
│                                         # SealRenderer, SignatureValidator,
│                                         # SignatureTransport
├── Data/                                 # final readonly value objects
├── Enums/                                # FontSize, ImageDriver, SignatureProfile,
│                                         # CertificationLevel, RevocationStatus,
│                                         # EncryptionAlgorithm, the ICP-Brasil three
├── Certificates/                         # readers, parser, vault, factory,
│                                         # ICP-Brasil and subjectAltName readers
├── Signing/
│   ├── PendingSignature.php              # the fluent builder
│   ├── IncrementalSigner.php             # bound to PdfSigner
│   ├── ArchiveExtender.php               # a further archive timestamp, no key needed
│   ├── Incremental/                      # revision writer, byte range, DSS, timestamps
│   ├── Encryption/                       # the standard security handler
│   └── Cades/                            # detached CMS, HTTP transport
├── Validation/                           # extractor, ASN.1 readers, verifier
├── Seal/InterventionSealRenderer.php
├── Support/                              # Files, ProcessRunner, TemporaryFile,
│                                         # PdfFilters, PngReader, SrgbProfile
├── Commands/                             # pdf:sign, pdf:validate-signature,
│                                         # a1-pdf-sign:check
├── Exceptions/                           # one class per failure mode, all sharing
│                                         # the A1PdfSignException interface
└── Testing/                              # test-only: certificates, a local timestamp
                                          # authority and a revocation one
config/a1-pdf-sign.php
```

## The contracts a consumer may replace

Five contracts are bound in the service provider, and two of them are
deliberately swappable by a consuming application:

| | |
|---|---|
| `Contracts\SealRenderer` | replace to draw a different seal: a logo, a QR code, any layout |
| `Contracts\SignatureTransport` | replace to own the TSA, OCSP and CRL calls, which is the SSRF surface (invariant 9) |

**Writing that down makes their signatures public API**, which is the cost and
is worth paying: they were already published contracts, and a consumer who
cannot find out they may be replaced has an extension point that does not
exist.

`SealRenderer::fromImage()` is how artwork produced elsewhere gets in, Blade
included. The package does not turn HTML into pixels
([0004](../decisions/0004-in-memory-seal.md)).

## Exceptions

One class per failure mode (0008), and **every one of them implements
`Exceptions\A1PdfSignException`**, which extends `Throwable`. That is what lets
a consuming application catch the package's failures as a group instead of
naming sixteen classes or catching `\Exception` and swallowing everything the
framework throws with them:

```php
$exceptions->report(function (A1PdfSignException $e) { … });
```

**The interface is surface**, and adding a class that does not implement it is a
hole in a promise rather than an oversight. `tests/Support/ExceptionsTest.php` builds its
dataset from the directory, so a new exception is covered the moment the file
exists.

`InvalidCertificatePasswordException` is the one distinction worth making by
type rather than by message: it is the most common failure in production, and it
**extends `InvalidCertificateContentException`**, the class it used to arrive
as, so catching the general failure still works.

## The builder

`newSignature()` returns a `Signing\PendingSignature`. It is the primary API.

```php
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signed = A1PdfSign::newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($pdfPath)
    ->info(name: 'Lucas', reason: 'Contract')
    ->seal()
    ->sign();
```

Certificate input, one of:

| Method | Takes |
|---|---|
| `certificate($path, $password)` | a PKCS#12 file on disk |
| `certificateFromUpload($file, $password)` | an `UploadedFile` |
| `certificatePem($path, $keyPath, $password)` | PEM, key combined or separate |
| `certificateFromPem($contents, $key, $password)` | PEM bytes already in hand |
| `usingCertificate($certificate)` | an already-parsed `Data\Certificate` |

`pdf($path, $password)` takes the **document's** password as its second
argument, when the document is encrypted. It is unrelated to the certificate's:
one opens the file, the other unlocks the key that signs it
([0030](../decisions/0030-signing-a-document-that-is-encrypted.md)).

Document input: `pdf($path)` or `pdfContents($bytes, $fileName)`.

Everything else is optional: `info()`, `seal()`, `sealFrom()`, `profile()`,
`timestamp()`, `fieldName()`. `sign()` closes the chain and returns a
`Data\SignedPdf`.

## The facade

One-shot entry points, for callers that do not need the builder:

```php
A1PdfSign::signFromFile($pfxPath, $password, $pdfPath);
A1PdfSign::signFromPem($pemPath, $password, $pdfPath, $keyPath);
A1PdfSign::signFromUpload($uploadedPfx, $password, $pdfPath);

A1PdfSign::encryptCertificate($certificate, $password);
A1PdfSign::decryptCertificate($hashKey, $encrypted, $password, $isBase64);

A1PdfSign::validate($pdfPath);      // Data\SignatureReport
A1PdfSign::signatureFields($pdfPath);
A1PdfSign::extendArchive($pdfPath); // a further archive timestamp, no certificate
A1PdfSign::icpBrasil($pfxPath, $password);   // Data\IcpBrasilReport

A1PdfSign::newSignature();          // Signing\PendingSignature
A1PdfSign::tempPath($tempFile, $fileExt);
```

Prefer injecting `Contracts\A1PdfSign` where you can: it is what makes the
package mockable in a consuming application's tests.

## Output

**`sign()` does not decide transport.** The same result answers all of these:

```php
$signed->contents();          // string, the signed bytes
$signed->size();              // int
$signed->save($path);         // string, the path written
$signed->download('doc.pdf'); // BinaryFileResponse, forces a download
$signed->toResponse();        // Response, renders inline
(string) $signed;             // same as contents()
```

Validation is symmetric:

```php
$report = A1PdfSign::validate($pdfPath);

$report->isValid();      // every signature verifies against the bytes it covers
$report->isSigned();
$report->count();
$report->signers();      // list<Data\Signer>
$report->timestamps();   // DocTimeStamps, classified separately
$report->latest();       // ?Data\SignatureDetails
```

Each `Data\SignatureDetails` also carries when it claims to have been signed:

```php
$signature->signedAt;                   // ?int, unix timestamp, null when absent
$signature->signerWasValidWhenSigned(); // ?bool, null when either date is unknown
```

`signedAt` is read from `/M` in the signature dictionary. That is inside the
range the signature covers, so altering it breaks the signature, but it is still
the signer's own clock: only an RFC 3161 timestamp, which `pades-b-t` and above
carry, makes the time attributable to a third party.

`signerWasValidWhenSigned()` returns `null` rather than `false` when the time or
the certificate dates are unknown. An absence is not a violation.

The certificates a signature embeds are also ordered into a chain, and the
document's long-term validation material is reported:

```php
$signature->chain;              // list<Data\Signer>, leaf first
$signature->chainReachesRoot;   // bool, whether it ends at a self-signed root

$report->securityStore;         // ?Data\SecurityStore
$report->hasLongTermMaterial(); // bool, material present for every signature
```

Each link in the chain is confirmed with the issuer's public key rather than by
matching names. None of this decides **trust**: whether the root is an authority
you accept stays with the application.

`isValid()` answers "does this signature match these bytes". It does not check
the issuer against a trust store: that decision stays with the application.

## Where the seal goes

`Data\SealPlacement` carries position, size and page. All three are read.

```php
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;

->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, page: 2))
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50))                   // the last page
->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, onEveryPage: true))
```

| | |
|---|---|
| `page` | 1-based, in the order the page tree declares. `SealPlacement::LAST_PAGE`, the default, is the last page |
| `onEveryPage` | the seal appears on every page, and wins over `page` |
| A page the document does not have | `SealPlacementException`, rather than clamping to the nearest one |

`onEveryPage` still produces **one** signature: the widget goes on the first page
and every further page gets a stamp annotation drawing the same appearance, so
the JPEG is embedded once whatever the page count
([0017](../decisions/0017-the-seal-goes-where-it-was-asked-for.md)).

Omitting `seal()` leaves the signature invisible, which is still a valid
signature: the seal is an appearance, not part of the cryptography.

## Trust

`isValid()` answers "does this signature match these bytes". Whether to accept
the signer is a separate question, and it is answered against roots the
application names:

```php
$store = TrustStore::fromFile(storage_path('icp-brasil.pem'));
// or ::fromPem($bundle), ::fromDirectory($path), ::empty()

$report = A1PdfSign::validate($path, $store);

$report->isTrusted();          // ?bool, across every signature
$report->latest()?->isTrusted; // ?bool, per signature
```

**The package ships no trust store and will not.** A bundled one goes stale
between releases, and shipping it would make this package's release cadence the
thing that decides whose signatures you accept
([0016](../decisions/0016-trust-is-the-applications-policy.md)).

Three answers, not two:

| | |
|---|---|
| `null` | no store was given. Nobody was asked, so there is nothing to report |
| `false` | a store was given and the chain does not reach it |
| `true` | the chain validates against it, path and all: intermediate validity, `basicConstraints`, key usage and name constraints, since OpenSSL does the checking |

An **untrusted** signature is not an **invalid** one. `isValid()` and
`isTrusted()` are independent, and a document can be one without the other.

## Certification signatures

A certification is the author's statement about what may happen to the document
from here on, rather than a signer's statement about what the bytes were
(ISO 32000-1 §12.8.2.2):

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->certify('form-filling')   // no-changes | form-filling | annotations
    ->sign();

$report->isCertified();               // bool
$report->certification;               // ?CertificationLevel
$report->acceptsFurtherSignatures();  // false only at no-changes
```

Three rules are enforced rather than documented, each raising
`CertificationException`: a certification has to be the **first** signature,
there can be only **one**, and a document certified at **`no-changes` cannot be
signed again** because a further signature is a further revision, which is what
that level forbids.

`certify()` defaults to `form-filling`, since a document that still has to be
signed is the common case.

**What a certification does depends on the reader honouring it**, and poppler
does not: measured with a differential test, it allows form filling on a
document certified at `no-changes` exactly as it does at `form-filling`. The
bytes are correct and the package enforces its own rules, but enforcement in the
reader is Adobe Reader and ITI Validar territory
([0012](../decisions/0012-certification-signatures.md)).

## Signing into a field the document already carries

A template laid out by someone else arrives with its fields placed, and the
application is expected to fill the right one rather than append a field beside
it:

```php
foreach (A1PdfSign::signatureFields($template) as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->pageNumber;  // 3
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
    $field->isVisible(); // true
}

A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($template)
    ->intoField('SignatureManager')
    ->seal()
    ->sign();
```

The field's own rectangle decides where the seal goes, so `intoField()` cannot
be combined with a `SealPlacement`, and a field with a zero rectangle keeps the
signature invisible even when `seal()` was called.

A field that is missing or already signed raises `SignatureFieldException`
rather than falling back to appending, which would reproduce exactly the failure
this exists to prevent ([0013](../decisions/0013-signing-into-an-existing-field.md)).

## What the signer accepts

Both cross-reference forms, and both PDF 1.5 compression structures:

| | |
|---|---|
| Classic cross-reference table, §7.5.4 | read and written |
| Cross-reference stream, §7.5.8 | read and written. The revision follows the form the document already uses, because mixing them produces a file readers do not see as signed ([0009](../decisions/0009-cross-reference-streams.md)) |
| Object stream, §7.5.7 | packed objects are read, and written back uncompressed by the revision that changes them ([0015](../decisions/0015-object-streams.md)) |

The last two travel together in practice. Word, "print to PDF" in Chrome and
LaTeX with compression emit both, and reading only the index is not enough:
signing rewrites the catalog, so a catalog packed into an object stream has to
be readable before the document can be signed at all.

## What the signer cannot do

Stated here because a public API is also its boundaries, and each has a record.

Two entries stood here until 2.5 and are gone, which is worth saying rather than
quietly deleting: encrypted documents were refused outright, and revocation
material was counted rather than read. Both were named here as limits, and both
were fixed by the records that named them
([0030](../decisions/0030-signing-a-document-that-is-encrypted.md) and
[0024](../decisions/0024-revocation-is-evaluated-not-counted.md)).

What is left:

| | |
|---|---|
| RC4-encrypted documents | refused, deliberately: signing one means writing RC4 back into it ([0030](../decisions/0030-signing-a-document-that-is-encrypted.md)) |
| An encrypted document packed into object streams | the streams holding the objects are encrypted too, so reading the catalog needs decryption on the way in. Reachable, not done |
| `pades-b-lt` and above, on an encrypted document | they append a security store and an archive timestamp whose streams this does not encrypt |
| A security handler other than the standard one | its key comes from somewhere this package cannot reach, by definition |
| Fetching revocation at validation time | evaluated from what the document carries, never from the network. That is a decision rather than a gap ([0024](../decisions/0024-revocation-is-evaluated-not-counted.md)) |
| Signing with an A3 token, a smart card or an HSM | out of scope: this package signs with A1 material it can hold |

## Signature profiles

`Enums\SignatureProfile` owns each level's `/SubFilter` and what it requires.

| Case | Value | Adds |
|---|---|---|
| `Legacy` | `legacy` | ISO 32000-1 detached CMS |
| `PadesBB` | `pades-b-b` | CAdES signed attributes. **The default** |
| `PadesBT` | `pades-b-t` | an RFC 3161 timestamp |
| `PadesBLT` | `pades-b-lt` | a Document Security Store |
| `PadesBLTA` | `pades-b-lta` | an archive timestamp over the whole file |

Every entry point accepts the enum case or its backing value, so configuration
can stay as plain strings. `timestamp()` is shorthand for `pades-b-t`.

## Configuration

Published with `--tag=a1-pdf-sign-config`. Nothing is required.

```php
'temp_path'  => env('A1_PDF_SIGN_TEMP_PATH'),   // null = system temp directory

'signature' => [
    'profile'          => env('A1_PDF_SIGN_PROFILE', 'pades-b-b'),
    'digest_algorithm' => env('A1_PDF_SIGN_DIGEST', 'sha256'),
    'timestamp'        => ['url' => …, 'username' => …, 'password' => …, 'timeout' => 20],
    'ltv'              => ['timeout' => 10],
],

'certificate' => [
    'use_path_env' => …,   // pass the host PATH to the openssl child process
    'legacy'       => …,   // openssl -legacy, for RC2/40-bit PFX under OpenSSL 3.x
],

'seal' => [
    'driver'     => 'gd',
    'font'       => ['path' => null, 'size' => 'large', 'color' => '#16A085'],
    'background' => null,
],
```

Nullable config-backed arguments mean "use the configured default" rather than
forcing every call site to repeat an infrastructure decision.

## Console

```
pdf:sign {pdfPath} {certificatePath} {password} {fileName?} {--key=}
pdf:validate-signature {pdfPath}
```

Both map a `Throwable` to a failure exit code, so they compose in a pipeline.

## Stability

`Data\*` are `final readonly` and are public return types, so **adding a property
changes the public shape**. The contracts in `Contracts\` may be implemented by
consumers, so adding a method to one is a breaking change for them even though
callers are unaffected.
