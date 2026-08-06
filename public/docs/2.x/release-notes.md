## 💥 Breaking Changes

### Version 2 is a clean break. The 1.x surface was removed, not deprecated.

There is no compatibility layer. Every 1.x entry point is gone, and the [Upgrade guide](/docs/2.x/upgrade-from-1x) maps each one to its replacement.

- The **global helper functions** — `signPdfFromFile()`, `signPdfFromUpload()`, `encryptCertData()`, `decryptCertData()`, `validatePdfSignature()`, `a1TempDir()` — no longer exist. Everything goes through the `A1PdfSign` facade;
- `LSNepomuceno\LaravelA1PdfSign\Sign\*` — `ManageCert`, `SignaturePdf`, `ValidatePdfSignature`, `SealImage` — was removed;
- `LSNepomuceno\LaravelA1PdfSign\Entities\*` was replaced by `Data\*`;
- String constants (`MODE_RESOURCE`, `FONT_SIZE_LARGE`, `IMAGE_DRIVER_GD`) became enums;
- Minimum requirements are now **Laravel 13** and **PHP 8.4**.

<hr>

## The reason for the rewrite

**A second signature used to destroy the first.** Signing re-imported every page through FPDI and rebuilt the document, which discarded annotations, form fields and any signature already present — the behaviour reported in [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430), open since 2021.

Version 2 signs by **appending a revision** (ISO 32000-1 §7.5.6). The original bytes survive byte for byte, so every earlier signature stays valid and each one covers the document as it stood when that signature was made.

Verified with poppler's `pdfsig` on a document carrying six signatures: all six report *Signature is Valid*.

<hr>

## Added

- **PAdES profiles** — B-B, B-T, B-LT and B-LTA, alongside the legacy ISO 32000-1 profile. See [Signature profiles](/docs/2.x/signature-profiles);
- **Real cryptographic validation.** 1.x reported a document as "validated" when the parsed subject contained a `CN` or `OU` field — which a tampered document still has. Validation now verifies the CMS against the bytes each signature covers;
- **Multiple signatures** on one document, each preserving the ones before it;
- **RFC 3161 timestamps**, embedded OCSP responses and CRLs (Document Security Store), and archive timestamps;
- **A fluent builder** as the primary API, plus contracts bound in the container so every part is swappable;
- **Publishable configuration** — temporary path, signature profile, timestamp authority, seal driver, font and background;
- `#[\SensitiveParameter]` on every password argument, so certificate passwords stop appearing in stack traces.

<hr>

## Removed

- **TCPDF and FPDI.** TCPDF 6 was discontinued by its author on 2026-05-30; the engine is now `tecnickcom/tc-lib-pdf-sign`, its official successor;
- The private key **written in plain text** to `src/Temp/` inside the consuming application's `vendor/`;
- The certificate password travelling on the command line, where `ps` exposed it to any user on the machine;
- The `openssl` binary as a hard requirement. Certificates are read through `ext-openssl`; the CLI remains only as the fallback for legacy PFX files under OpenSSL 3.x.
