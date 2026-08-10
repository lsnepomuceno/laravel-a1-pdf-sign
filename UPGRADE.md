# Upgrading

## From 2.3.0 to 2.3.1

Two fixes. One of them **moves where an existing seal is drawn**, so read this
before upgrading a multi-page document pipeline.

### The seal now goes on the page the placement names

`Data\SealPlacement` has carried `$page` and `$onEveryPage` since 2.0 and
nothing read either of them: every seal landed on the first page, whatever was
asked for.

| | Before | Now |
|---|---|---|
| `new SealPlacement(...)`, no page given | first page | **last page**, which `$page`'s default, `LAST_PAGE`, has always named |
| `page: 2` | first page | page 2 |
| `onEveryPage: true` | first page | every page |
| A page the document does not have | first page | `SealPlacementException` |

Single-page documents are unaffected in every case.

**If your seals were landing on page 1 of a multi-page document and you want
them to stay there, pass `page: 1` explicitly.** The value was previously
ignored, so no existing call site can be relying on it having meant anything
else.

`onEveryPage` still produces one signature: the widget goes on the first page it
applies to, and every further page gets a stamp annotation drawing the same
appearance ([0017](docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md)).

### `TrustStore::fromDirectory()` works on Alpine

It globbed with `GLOB_BRACE`, a constant PHP leaves undefined on musl, so the
call was a fatal error on `php:8.4-alpine`. No API change; if you were not on
musl, nothing about it changes for you.

## From 2.2 to 2.3

Additive for applications. Nothing was removed, no behaviour changed for code
that already worked, and the PHP and Laravel requirements do not move.

### The contracts gained trailing optional parameters

| | 2.2 | 2.3 |
|---|---|---|
| `Contracts\A1PdfSign::validate()` | `$pdfPath` | gains `?TrustStore $trust = null` |
| `Contracts\SignatureValidator::validateFile()` | `$pdfPath` | gains `?TrustStore $trust = null` |
| `Contracts\SignatureValidator::validate()` | `$pdfContents, $label` | gains `?TrustStore $trust = null` |
| `Data\SignatureDetails::toArray()` | 10 keys | gains `isTrusted` |

Calling them is unaffected. Implementing them is not, so a test double or a
custom validator bound in the container has to be updated.

### New: verifying against a trust store

```php
$store = TrustStore::fromFile(storage_path('icp-brasil.pem'));

$report = A1PdfSign::validate($path, $store);

$report->isTrusted();          // ?bool
$report->latest()?->isTrusted; // ?bool
```

**Null is not false.** A document validated without a store reports trust as
null, because nobody was asked. An empty store, `TrustStore::empty()`, is the
different answer: it trusts nothing, so every signature reports false.

The package ships no trust store and will not.

### New: documents whose objects are packed

No API change. Word, "print to PDF" in Chrome and LaTeX with compression pack
the catalog and pages into an object stream (ISO 32000-1 §7.5.7). 2.2 read the
cross-reference stream that indexes them and still refused the documents,
because signing rewrites the catalog. 2.3 signs them.

## From 2.1 to 2.2

2.2 is additive for applications. Nothing was removed, no behaviour changed for
code that already worked, and the PHP and Laravel requirements do not move. An
application that signs and validates upgrades without editing anything.

Two changes reach code that **extends** the package rather than calls it, and
one reaches anyone who compares a report's array form byte for byte.

### The contracts gained methods and parameters

| | 2.1 | 2.2 |
|---|---|---|
| `Contracts\A1PdfSign` | n/a | gains `signatureFields()` |
| `Contracts\PdfSigner::sign()` | 7 parameters | gains `?string $intoField` and `?CertificationLevel $certification`, both trailing and optional |

Injecting or calling them is unaffected: the new parameters are optional and
positional callers are untouched. Implementing them is not, so a test double or
a custom signer bound in the container has to be updated:

```php
public function signatureFields(string $pdfPath): array;   // list<SignatureField>

public function sign(
    string $pdfContents,
    Certificate $certificate,
    SignatureInfo $info,
    string $fieldName = 'Signature',
    ?SealImage $seal = null,
    ?SealPlacement $placement = null,
    ?SignatureProfile $profile = null,
    ?string $intoField = null,              // new
    ?CertificationLevel $certification = null,   // new
): SignedPdf;
```

### `InvalidPdfFileException` takes a message

The constructor took the offending filename and built the sentence itself. It
now takes the message, and the one case the old wording described moved to a
named constructor:

```php
new InvalidPdfFileException('/tmp/contract.docx');        // 2.1
InvalidPdfFileException::extension('/tmp/contract.docx'); // 2.2, same string
```

The wording is preserved byte for byte, so a test asserting on it still passes.
Fifteen of the sixteen places that raised this were reporting structural faults,
and every one of them said "Invalid file extension"
([0008](docs/decisions/0008-exceptions-name-the-real-fault.md)).

Positional callers of `new InvalidPdfFileException(...)` outside the package are
unaffected in behaviour, since the first argument is still a string that becomes
the message. Only a named argument breaks:

```php
new InvalidPdfFileException(currentFile: $path);  // 2.1
new InvalidPdfFileException(message: $text);      // 2.2
```

### `SignatureReport` gained a property

`Data\SignatureReport` is a public return type, so a new property changes what
`toArray()` returns:

```php
// 2.1
['signatures' => [...], 'securityStore' => ...]

// 2.2
['signatures' => [...], 'securityStore' => ..., 'certification' => null]
```

Reading properties and calling methods is unaffected. Only code asserting on the
whole array, a snapshot test or a strict equality check, has to be updated.

### A document may now refuse to be signed

`sign()` can raise two exceptions it never raised before, both of them
deliberate refusals rather than failures:

| | |
|---|---|
| `SignatureFieldException` | only when `intoField()` was used |
| `CertificationException` | when the document is certified at `no-changes`, which forbids the further revision a signature would append |

The second can reach code that does not use certification at all, if it signs a
document someone else certified. That is the certification working: at
`no-changes` a further signature would silently invalidate the one already
there, so it is refused instead.

### New: signing into a template's own fields

```php
foreach (A1PdfSign::signatureFields($template) as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
}

A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($template)
    ->intoField('SignatureManager')
    ->seal()              // drawn into the field's own rectangle
    ->sign();
```

Previously the package appended a new field beside the empty one, so a template
ended up with a signature in the wrong place and its own field still unfilled.

### New: certification signatures

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->certify('form-filling')   // no-changes | form-filling | annotations
    ->sign();
```

### New: documents with cross-reference streams

No API change. Documents produced by Word, by "print to PDF" in Chrome and by
most modern generators use the cross-reference stream of ISO 32000-1 §7.5.8
rather than the classic table, and 2.1 refused them. 2.2 signs them, appending a
revision in whichever form the document already uses.

## From 2.0 to 2.1

2.1 adds PEM as a second accepted certificate encoding. PKCS#12 behaviour is
unchanged, and the PHP and Laravel requirements do not move, so an application
that signs with `.pfx` and does not implement the package's contracts upgrades
without editing anything.

Two changes reach code that extends the package rather than calls it.

### The contracts gained a method and renamed a parameter

| | 2.0 | 2.1 |
|---|---|---|
| `Contracts\A1PdfSign` | n/a | gains `signFromPem()` |
| `Contracts\CertificateReader::read()` | `$pfxContents` | `$contents` |

Injecting the contracts, or calling them, is unaffected. Implementing them is
not: a test double or a custom reader bound in the container has to be updated.

```php
public function signFromPem(
    string $pemPath,
    string $password,
    string $pdfPath,
    ?string $privateKeyPath = null,
): SignedPdf;
```

`read()` is called positionally everywhere in the package, so the rename only
reaches you through a named argument:

```php
$reader->read(pfxContents: $bytes, password: $password);   // 2.0
$reader->read(contents: $bytes, password: $password);      // 2.1
```

The parameter was named after PKCS#12 when that was the only encoding a reader
could ingest. It no longer is: `PemCertificateReader` implements the same
contract as the degenerate case, the reader whose conversion step is empty.

### `pdf:sign` renamed its second argument

```bash
php artisan pdf:sign contract.pdf certificate.pfx secret signed.pdf
```

The invocation above still works: `pfxPath` became `certificatePath`, and
console arguments are matched by position. Only `Artisan::call()` with named
keys breaks.

```php
Artisan::call('pdf:sign', ['pfxPath' => $path, ...]);          // 2.0
Artisan::call('pdf:sign', ['certificatePath' => $path, ...]);  // 2.1
```

The command also takes `--key` for a PEM key in its own file. Passing it with a
PKCS#12 bundle is rejected rather than ignored: the bundle already carries its
key, so the combination means the caller is mistaken about what they hold.

### New: PEM certificates

The encoding is decided by content, not by extension: PEM ships as `.pem`,
`.crt`, `.cer`, `.key` and `.txt`, and gating on the suffix would reject valid
files. The certificate and its private key may sit in one file or in two.

```php
A1PdfSign::signFromPem($pemPath, $password, $pdfPath);                 // one-shot
A1PdfSign::signFromPem($certPath, $password, $pdfPath, $keyPath);      // key in its own file

A1PdfSign::newSignature()
    ->certificatePem($certificatePath, $keyPath, $password)            // $keyPath null when combined
    ->pdf($pdfPath)
    ->sign();

A1PdfSign::newSignature()
    ->certificateFromPem($bytes, $keyBytes);                           // from an upload or a secret store
```

`$password` defaults to empty, because **a PEM private key is frequently
unencrypted, legal for PEM and impossible for PKCS#12**. OpenSSL ignores a
passphrase given for a key that does not need one, so the argument is safe to
pass either way. Prefer an encrypted key where you have the choice: an
unprotected one is readable by anything that can read the file.

`encryptCertificate()` gained no sibling. It takes "a certificate" generically
and detects the encoding, where signing keeps explicit entry points so the
caller states what it holds.

Content that is neither valid PEM nor routable, whether binary DER or PKCS#12 bytes
handed to the PEM entry point, raises the new
`Exceptions\InvalidPemContentException`, naming the offending half rather than
reporting a generic parse failure. A certificate and key that are both valid
but unrelated keep raising `InvalidX509PrivateKeyException`.

## From 1.x to 2.0

Version 2.0 is a clean break: the deprecated API is removed rather than carried
behind a shim. Upgrading requires editing your code.

That was a deliberate reversal: the original plan kept a deprecation layer
until 3.0. A 3.0 is far enough out that a shim living "until then" is a shim
maintained indefinitely, and every one of them constrains the design it wraps:
`Entities\*` could not be `final`, the enums would carry legacy backing values,
and the global helpers would keep the global namespace occupied. Since the PHP
8.4 and Laravel 13 floor already forces a deliberate upgrade, the marginal cost
of also renaming call sites is small.

If you cannot move yet, stay on `^1`, which remains maintained on the
`v1.x-dev` branch.

### Requirements

| | 1.x | 2.0 |
|---|---|---|
| PHP | 8.1 – 8.4 | **8.4 – 8.5** |
| Laravel | 9 – 12 | **13** |

Laravel 10 and 11 are past their security-support windows, and neither supports
PHP 8.5. Laravel 12 does support PHP 8.5, but it requires `symfony/process
^7.2` while Pest 5 requires `^8.1`: the two cannot be installed in the same
tree, so the cell could never be tested.

### Global helpers are removed

All six now live on the `A1PdfSign` facade, or on the
`LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign` contract you can inject.

| 1.x | 2.0 |
|---|---|
| `signPdfFromFile($pfx, $pass, $pdf, $mode, $usePathEnv)` | `A1PdfSign::signFromFile($pfx, $pass, $pdf, $usePathEnv)` |
| `signPdfFromUpload($upload, $pass, $pdf, $mode, $usePathEnv)` | `A1PdfSign::signFromUpload($upload, $pass, $pdf, $usePathEnv)` |
| `encryptCertData($pfx, $pass, $usePathEnv)` | `A1PdfSign::encryptCertificate($pfx, $pass, $usePathEnv)` |
| `decryptCertData($hash, $cert, $pass, $isBase64, $usePathEnv)` | `A1PdfSign::decryptCertificate($hash, $cert, $pass, $isBase64, $usePathEnv)` |
| `validatePdfSignature($pdf)` | `A1PdfSign::validate($pdf)` |
| `a1TempDir($tempFile, $ext)` | `A1PdfSign::tempPath($tempFile, $ext)` |

The trailing arguments are now optional and fall back to
`config('a1-pdf-sign')`, so `usePathEnv` no longer has to be repeated at every
call site.

Prefer injecting the contract where you can: it is what makes the package
mockable in your own tests:

```php
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;

public function __construct(private readonly A1PdfSign $signer) {}
```

### Entities became Data

| 1.x | 2.0 |
|---|---|
| `Entities\CertificateProcessed` | `Data\Certificate` |
| `Entities\EncryptedCertificate` | `Data\EncryptedCertificate` |
| `Entities\ValidatedSignedPDF` | `Data\SignatureReport` |
| `Entities\BaseEntity` | `Data\BaseData` |

Property names are unchanged, so only the imports move. The classes are now
`final readonly`; if you were extending or mutating them, that no longer works.

`Data\Certificate` also gained `expiresAt()`, `isExpired()` and `commonName()`,
which read the parsed x509 data you previously had to dig out of the `data`
array yourself.

### String constants became enums

| 1.x | 2.0 |
|---|---|
| `SealImage::FONT_SIZE_SMALL` | `Enums\FontSize::Small` |
| `SealImage::FONT_SIZE_MEDIUM` | `Enums\FontSize::Medium` |
| `SealImage::FONT_SIZE_LARGE` | `Enums\FontSize::Large` |
| `SealImage::IMAGE_DRIVER_GD` | `Enums\ImageDriver::Gd` |
| `SealImage::IMAGE_DRIVER_IMAGICK` | `Enums\ImageDriver::Imagick` |
| `SignaturePdf::MODE_RESOURCE` | removed, see below |
| `SignaturePdf::MODE_DOWNLOAD` | removed, see below |

Every entry point accepts either the enum case or its backing value
(`'large'`, `'gd'`), so configuration can stay as plain strings.

**The signing mode has no replacement, by design.** `sign()` returns a
`SignedPdf` and no longer decides how the result is delivered: the same result
answers `contents()`, `save()`, `download()` and `toResponse()`. Drop the mode
argument and call the method you want.

### The signing classes are gone

`Sign\SignaturePdf` and `Sign\SealImage` are removed. Signing now goes through
the fluent builder, and the seal is rendered by the `SealRenderer` contract:

```php
$signed = A1PdfSign::newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($pdfPath)
    ->info(name: 'Lucas', reason: 'Contract')
    ->seal()                 // omit for an invisible signature
    ->sign();                // → SignedPdf

$signed->contents();         // string
$signed->save($path);        // path
$signed->download();         // BinaryFileResponse
$signed->toResponse();       // inline
```

`setasign/fpdi` and `tecnickcom/tcpdf` are no longer dependencies.

**Signing no longer rebuilds the document.** v1 imported every page into a new
file, which silently discarded annotations, form fields and any signature
already present. v2 appends a revision instead, so the original bytes survive
and a document can carry more than one signature, the request in
[TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430).

The practical consequence is that output is no longer byte-comparable with 1.x.

### New: PAdES signature profiles

Signatures are now PAdES baseline by default, carrying the ESS
`signing-certificate-v2` attribute that `openssl_pkcs7_sign()` cannot emit.

| Profile | Adds |
|---|---|
| `legacy` | ISO 32000-1 detached CMS, the 1.x behaviour |
| `pades-b-b` | CAdES signed attributes. **The new default** |
| `pades-b-t` | B-B plus an RFC 3161 timestamp |
| `pades-b-lt` | B-T plus a Document Security Store, so the signature still verifies after the certificate expires |
| `pades-b-lta` | B-LT plus an archive timestamp over the whole file |

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->timestamp()                       // shorthand for pades-b-t
    ->sign();
```

Set the default in `config/a1-pdf-sign.php`, and the timestamp authority in
`A1_TSA_URL`. Choosing `legacy` reproduces the 1.x `/SubFilter`.

### New: publishable configuration

```bash
php artisan vendor:publish --tag=a1-pdf-sign-config
```

It controls the temporary path, the `openssl` PATH and legacy flags, and the
seal defaults. Nothing is required: the defaults match 1.x behaviour, except
that temporary files no longer need `vendor/` to be writable.

### Validation now verifies the signature

In 1.x, `validatePdfSignature()` returned `isValidated = true` when the
embedded certificate happened to carry a `CN` or `OU` field. It never checked
whether the signature matched the document, so a tampered file still reported
as validated.

`SignatureReport` is therefore reshaped:

| 1.x | 2.0 |
|---|---|
| `$report->isValidated` | `$report->isValid()`, every signature verifies |
| `$report->data` | `$report->signers()`, or `$report->signatures` for detail |
| n/a | `$report->count()`, `isSigned()`, `latest()` |

Each entry carries the signer as structured data, whether it verified, and
whether it covers the whole file. Documents with more than one signature are
reported in full; 1.x read only the first.

`isValid()` answers "does this signature match these bytes". It does not check
the issuer against a trust store: that decision stays with your application.
