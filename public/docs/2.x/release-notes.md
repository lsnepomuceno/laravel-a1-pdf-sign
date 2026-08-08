# 2.1.0

## PEM certificates

**PKCS#12 is no longer the only encoding the package reads.** A PEM certificate can be handed to the signer directly, with no `openssl pkcs12 -export` step first — which was the only answer this package had for two years ([discussion #147](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/147)).

```PHP
A1PdfSign::signFromPem('path/to/certificate.pem', 'password', 'path/to/document.pdf');

A1PdfSign::newSignature()
    ->certificatePem('path/to/certificate.crt', 'path/to/private.key', 'password')
    ->pdf('path/to/document.pdf')
    ->sign();
```

PKCS#12 is not a peer of PEM but a container that is converted into it, so the two share one pipeline: the encoding is decided at the entry point and everything after it — profiles, seals, timestamps, multiple signatures, validation — is the same code. `PemCertificateReader` implements the existing `CertificateReader` contract as the degenerate case, the reader whose conversion step is empty.

<hr>

## Added

- **`certificatePem()` and `certificateFromPem()`** on the builder, and **`signFromPem()`** on the facade and the `A1PdfSign` contract. The private key may sit in the same file as the certificate or in one of its own;
- **The encoding is read from the content, never the extension.** PEM ships as `.pem`, `.crt`, `.cer`, `.key` and `.txt`, so gating on the suffix would reject valid files;
- **An empty password is accepted**, because a PEM private key is frequently unencrypted — legal for PEM, impossible for PKCS#12. OpenSSL ignores a passphrase given for a key that does not need one;
- **`pdf:sign` takes `--key`** for the two-file form. Passing it with a PKCS#12 bundle is rejected rather than ignored: the bundle already carries its key, so the combination means the caller is mistaken about what they hold;
- **`encryptCertificate()` accepts PEM**, detecting the encoding instead of gaining a sibling — it takes "a certificate" generically, where signing keeps explicit entry points;
- **`InvalidPemContentException`**, which names the offending half — binary DER or PKCS#12 bytes handed to the PEM entry point are reported as misrouted, not as a generic parse failure;
- **`samples/certificate.pem`**, the identity `samples/certificate.pfx` already carried, in the second encoding.

<hr>

## Fixed

- **A passphrase-protected private key could not be checked against its certificate.** `CertificateParser` passed the bundle to `openssl_x509_check_private_key()` as a string, which cannot decrypt it. PKCS#12 never reached that path — `openssl_pkcs12_read()` returns an already-decrypted key — so the defect only surfaced once PEM arrived. The array form is correct for encrypted and unencrypted keys alike, so nothing branches on it.

<hr>

## ⚠️ Breaking for implementers

Calling or injecting the contracts is unaffected. **Implementing them is not.**

| | 2.0 | 2.1 |
| --- | --- | --- |
| `Contracts\A1PdfSign` | — | gains `signFromPem()` |
| `Contracts\CertificateReader::read()` | `$pfxContents` | `$contents` |

The parameter was named after PKCS#12 when that was the only encoding a reader could ingest. Every call site in the package is positional, so the rename reaches you only through a named argument. The `pdf:sign` argument `pfxPath` became `certificatePath` for the same reason — positional on the command line, so only `Artisan::call()` with named keys is affected.

The [upgrade guide](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/UPGRADE.md) maps each one.

<hr>
<hr>

# 2.0.0

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
