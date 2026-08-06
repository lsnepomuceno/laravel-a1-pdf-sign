# Upgrading

## From 1.x to 2.0

Version 2.0 is a clean break: the deprecated API is removed rather than carried
behind a shim. Upgrading requires editing your code.

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

Prefer injecting the contract where you can — it is what makes the package
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
| `SignaturePdf::MODE_RESOURCE` | removed — see below |
| `SignaturePdf::MODE_DOWNLOAD` | removed — see below |

Every entry point accepts either the enum case or its backing value
(`'large'`, `'gd'`), so configuration can stay as plain strings.

**The signing mode has no replacement, by design.** `sign()` returns a
`SignedPdf` and no longer decides how the result is delivered — the same result
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
and a document can carry more than one signature — the request in
[TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430).

The practical consequence is that output is no longer byte-comparable with 1.x.

### New: PAdES signature profiles

Signatures are now PAdES baseline by default, carrying the ESS
`signing-certificate-v2` attribute that `openssl_pkcs7_sign()` cannot emit.

| Profile | Adds |
|---|---|
| `legacy` | ISO 32000-1 detached CMS — the 1.x behaviour |
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
seal defaults. Nothing is required — the defaults match 1.x behaviour, except
that temporary files no longer need `vendor/` to be writable.

### Validation now verifies the signature

In 1.x, `validatePdfSignature()` returned `isValidated = true` when the
embedded certificate happened to carry a `CN` or `OU` field. It never checked
whether the signature matched the document, so a tampered file still reported
as validated.

`SignatureReport` is therefore reshaped:

| 1.x | 2.0 |
|---|---|
| `$report->isValidated` | `$report->isValid()` — every signature verifies |
| `$report->data` | `$report->signers()`, or `$report->signatures` for detail |
| — | `$report->count()`, `isSigned()`, `latest()` |

Each entry carries the signer as structured data, whether it verified, and
whether it covers the whole file. Documents with more than one signature are
reported in full; 1.x read only the first.

`isValid()` answers "does this signature match these bytes". It does not check
the issuer against a trust store — that decision stays with your application.
