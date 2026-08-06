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
| Laravel | 9 – 12 | **12 – 13** |

Laravel 10 and 11 are past their security-support windows, and neither supports
PHP 8.5.

### Global helpers are removed

All six now live on the `A1PdfSign` facade, or on the
`LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign` contract you can inject.

| 1.x | 2.0 |
|---|---|
| `signPdfFromFile($pfx, $pass, $pdf, $mode, $usePathEnv)` | `A1PdfSign::signFromFile($pfx, $pass, $pdf, $mode, $usePathEnv)` |
| `signPdfFromUpload($upload, $pass, $pdf, $mode, $usePathEnv)` | `A1PdfSign::signFromUpload($upload, $pass, $pdf, $mode, $usePathEnv)` |
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
| `SignaturePdf::MODE_RESOURCE` | `Enums\SignatureMode::Resource` |
| `SignaturePdf::MODE_DOWNLOAD` | `Enums\SignatureMode::Download` |

Every entry point accepts either the enum case or its backing value
(`'large'`, `'gd'`, `'resource'`), so configuration can stay as plain strings.

### New: publishable configuration

```bash
php artisan vendor:publish --tag=a1-pdf-sign-config
```

It controls the temporary path, the `openssl` PATH and legacy flags, and the
seal defaults. Nothing is required — the defaults match 1.x behaviour, except
that temporary files no longer need `vendor/` to be writable.

### Known issue carried from 1.x

`encryptCertData()` and `decryptCertData()` never round-tripped: the first
stores a PEM, the second expects binary PKCS#12. The 2.0 equivalents currently
behave the same. See `ARCHITECTURE-V2.md` §1.14.
