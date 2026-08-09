# Upgrading from 1.x

Version 2.0 is a clean break: the deprecated API was removed rather than carried behind a shim. **Upgrading requires editing your code.**

If you cannot move yet, stay on `^1`, which remains maintained on the `v1.x-dev` branch.

<hr>

## Requirements

| | 1.x | 2.0 |
| --- | --- | --- |
| PHP | 8.1 – 8.4 | **8.4 – 8.5** |
| Laravel | 9 – 12 | **13** |

Laravel 10 and 11 are past their security-support windows, and neither supports PHP 8.5. Laravel 12 does support PHP 8.5, but it requires `symfony/process ^7.2` while the test stack requires `^8.1`: the two cannot be installed in the same tree, so that combination could never be tested.

<hr>

## Global helpers are removed

All six now live on the `A1PdfSign` facade, or on the `LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign` contract you can inject.

| 1.x | 2.0 |
| --- | --- |
| `signPdfFromFile($pfx, $pass, $pdf, $mode, $usePathEnv)` | `A1PdfSign::signFromFile($pfx, $pass, $pdf, $usePathEnv)` |
| `signPdfFromUpload($upload, $pass, $pdf, $mode, $usePathEnv)` | `A1PdfSign::signFromUpload($upload, $pass, $pdf, $usePathEnv)` |
| `encryptCertData($pfx, $pass, $usePathEnv)` | `A1PdfSign::encryptCertificate($pfx, $pass, $usePathEnv)` |
| `decryptCertData($hash, $cert, $pass, $isBase64, $usePathEnv)` | `A1PdfSign::decryptCertificate($hash, $cert, $pass, $isBase64, $usePathEnv)` |
| `validatePdfSignature($pdf)` | `A1PdfSign::validate($pdf)` |
| `a1TempDir($tempFile, $ext)` | `A1PdfSign::tempPath($tempFile, $ext)` |

The trailing arguments are optional and fall back to `config('a1-pdf-sign')`, so `usePathEnv` no longer has to be repeated at every call site.

Prefer injecting the contract where you can: it is what makes the package mockable in your own tests:

```PHP
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;

public function __construct(private readonly A1PdfSign $signer) {}
```

<hr>

## Entities became Data

| 1.x | 2.0 |
| --- | --- |
| `Entities\CertificateProcessed` | `Data\Certificate` |
| `Entities\EncryptedCertificate` | `Data\EncryptedCertificate` |
| `Entities\ValidatedSignedPDF` | `Data\SignatureReport` |
| `Entities\BaseEntity` | `Data\BaseData` |

Property names are unchanged, so only the imports move. The classes are now `final readonly`; if you were extending or mutating them, that no longer works.

`Data\Certificate` also gained `expiresAt()`, `isExpired()` and `commonName()`, which read the parsed x509 data you previously had to dig out of the `data` array yourself.

<hr>

## String constants became enums

| 1.x | 2.0 |
| --- | --- |
| `SealImage::FONT_SIZE_SMALL` | `Enums\FontSize::Small` |
| `SealImage::FONT_SIZE_MEDIUM` | `Enums\FontSize::Medium` |
| `SealImage::FONT_SIZE_LARGE` | `Enums\FontSize::Large` |
| `SealImage::IMAGE_DRIVER_GD` | `Enums\ImageDriver::Gd` |
| `SealImage::IMAGE_DRIVER_IMAGICK` | `Enums\ImageDriver::Imagick` |
| `SignaturePdf::MODE_RESOURCE` | removed, see below |
| `SignaturePdf::MODE_DOWNLOAD` | removed, see below |

Every entry point accepts either the enum case or its backing value (`'large'`, `'gd'`), so configuration can stay as plain strings.

**The signing mode has no replacement, by design.** `sign()` returns a `SignedPdf` and no longer decides how the result is delivered: the same result answers `contents()`, `save()`, `download()` and `toResponse()`. Drop the mode argument and call the method you want.

<hr>

## The signing classes are gone

`Sign\ManageCert`, `Sign\SignaturePdf`, `Sign\ValidatePdfSignature` and `Sign\SealImage` were removed. Signing goes through the fluent builder:

```PHP
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

**Signing no longer rebuilds the document.** 1.x imported every page into a new file, which silently discarded annotations, form fields and any signature already present. 2.0 appends a revision instead, so the original bytes survive and a document can carry more than one signature, the request in [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430).

The practical consequence is that **output is no longer byte-comparable with 1.x**. If you have tests asserting on signed bytes, they will need rewriting against the structure instead.

<hr>

## Storing certificates: the round trip now works

In 1.x, `encryptCertData()` stored the PEM bundle and `decryptCertData()` wrote that PEM to a `.pfx` file and fed it to `openssl pkcs12 -in`, which expects binary PKCS#12:

```
asn1 encoding routines:asn1_check_tlen:wrong tag ... Type=PKCS12
```

**Half of the certificate-storage API therefore never worked.** It went unnoticed because the 1.x suite only ever called `encryptCertData()` and asserted the shape of its return value, never reading it back.

If you have encrypted certificates stored, they were written as PEM and 2.0 reads them as PEM. Nothing to migrate: the reading half simply starts working.

<hr>

## Validation now verifies the signature

In 1.x, `validatePdfSignature()` returned `isValidated = true` when the embedded certificate happened to carry a `CN` or `OU` field. It never checked whether the signature matched the document, so a tampered file still reported as validated.

`SignatureReport` is reshaped accordingly:

| 1.x | 2.0 |
| --- | --- |
| `$report->isValidated` | `$report->isValid()`, every signature verifies |
| `$report->data` | `$report->signers()`, or `$report->signatures` for detail |
| n/a | `$report->count()`, `isSigned()`, `latest()`, `timestamps()` |

Documents with more than one signature are reported in full; 1.x read only the first `/ByteRange` it found.

**Expect documents that used to pass to start failing.** That is the point: if a file reports invalid under 2.0 and valid under 1.x, 1.x was not checking.

<hr>

## New in 2.0

- [Signature profiles](/docs/2.x/signature-profiles): PAdES B-B is the new default; `legacy` reproduces the 1.x `/SubFilter`;
- [Publishable configuration](/docs/2.x/installation): temporary path, profile, timestamp authority and seal defaults;
- Multiple signatures on one document;
- `#[\SensitiveParameter]` on every password argument, so certificate passwords stop appearing in stack traces.
