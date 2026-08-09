# 2.2.1

## A break 2.2.0 shipped, and the gate that found it

2.2.0 raised `IncrementalSigner`'s constructor from six required arguments to eight. Resolving the signer from the container, which every documented path does, is unaffected. **Building it by hand is not**, and that is a break a patch release should undo rather than a note in an upgrade guide.

Both new parameters now default to an instance:

```PHP
new IncrementalSigner($reader, $writer, $byteRange, $cades, $dss, $archiveTimestamp);
```

Nothing else changed. There is no reason to upgrade from 2.2.0 unless you construct that class directly.

<hr>

## How it was found

The Roave backward compatibility check now runs on every pull request, comparing the last release against `HEAD`. It was deliberately held back from the 2.2 work so its first run could be read rather than merged blind, and it earned its place immediately: **nothing in the test suite could have caught this**, because the suite resolves everything from the container.

It also surfaced a second change 2.2.0 made and failed to document: `InvalidPdfFileException::__construct()` renamed its first parameter from `currentFile` to `message`. Behaviour is unchanged for a positional caller, since the argument is still a string that becomes the message. Only a named argument breaks:

```PHP
new InvalidPdfFileException(currentFile: $path);  // 2.1
new InvalidPdfFileException(message: $text);      // 2.2
```

The one case the old wording described kept it byte for byte, as `InvalidPdfFileException::extension($path)`.

<hr>

## Fixed

- **`Signing\IncrementalSigner::__construct()` accepts six arguments again.** `SignatureFieldReader` and `CertificationReader` default to an instance instead of being required.

## Added

- **A backward compatibility gate on every pull request**, comparing the last SemVer tag against `HEAD`. The baseline moves on its own as releases are cut, so there is no list to maintain.

<hr>

# 2.2.0

## Templates, certification, and the documents 2.1 refused

Three things a real workflow needs and 2.1 could not do: sign a PDF produced by Word or Chrome, fill the signature field a contract template already carries, and certify a document as its author.

```PHP
// The field the template already drew, filled by name
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($contract)
    ->intoField('SignatureManager')
    ->seal()
    ->sign();

// The author's statement about what may happen from here on
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($contract)
    ->certify('form-filling')
    ->sign();
```

Nothing was removed and the PHP and Laravel requirements do not move, so an application that signs and validates upgrades without editing anything.

<hr>

## Documents 2.1 could not sign at all

**PDF 1.5 replaced the cross-reference table with a stream**, and that is what Word, "print to PDF" in Chrome, LaTeX with compression and most modern generators emit. 2.1 refused them, which bounded who could use the package, and the bound was not small.

2.2 reads that form and appends a revision in whichever form the document already uses. The mixture is not a matter of taste: appending a classic table to a document whose latest section is a stream produced a file poppler reported as carrying **no signatures at all**. Reading shipped one release before writing, with signing refusing in between, so the gap was a loud refusal rather than silent corruption.

<hr>

## Added

- **`intoField()`**, which fills a signature field the document already carries instead of appending one beside it. Until now the package left the template's own field empty while putting a valid signature somewhere else. The field's rectangle decides where the seal goes;
- **`A1PdfSign::signatureFields()`**, returning each field's name, rectangle, page and whether it is already signed;
- **`certify()`**, writing a `/DocMDP` certification at `no-changes`, `form-filling` or `annotations` (ISO 32000-1 §12.8.2.2), requested in [discussion #160](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/160);
- **Cross-reference stream support**, reading and writing, with no API change;
- **`signedAt` and `signerWasValidWhenSigned()`** on each signature. The second returns `null` rather than `false` when the signing time is absent, because a validity window that cannot be checked is unknown, not invalid;
- **`chain` and `chainReachesRoot`**, the embedded certificates ordered leaf first, with each link confirmed by the issuer's public key rather than by matching names;
- **`securityStore` and `hasLongTermMaterial()`**, so a `pades-b-lt` document can be asked whether its validation material actually covers every signature in it;
- **`isCertified()`, `certification` and `acceptsFurtherSignatures()`** on the report;
- **Archive timestamps are verified** rather than assumed, against the imprint they actually stamp;
- **`samples/certified.pdf`, `samples/signed-into-fields.pdf` and `samples/xref-stream.pdf`**, one per new capability.

<hr>

## Refusals, which are the feature

Each of these raises rather than falling back, because every fallback here produces a file that looks right and is not.

| Refused | Why |
|---|---|
| `intoField()` naming a field that does not exist | appending one beside it is exactly the failure the feature prevents. The message names the fields that do exist |
| `intoField()` naming a field already signed | filling it again would replace that signature rather than add one |
| A seal placement passed with `intoField()` | the field has its own rectangle; resolving by precedence would silently move the seal off the box the template drew |
| A second certification, or one after an approval signature | a certification states what may happen from here on, and a signature already applied is a thing that happened |
| **Any signature on a document certified at `no-changes`** | a further signature is a further revision, which is exactly what that level forbids |
| An encrypted document | the cross-reference table is not encrypted, so reading gets far enough to look successful while everything around it is unreadable |

The `no-changes` one can reach code that uses no new feature at all, if it signs a document someone else certified. That is the certification working: without the refusal, the second signature would silently invalidate the first.

<hr>

## Fixed

- **The first page of a compact document was misidentified as the catalog.** The page search scanned a fixed 400-byte window from each object's offset, which in a document whose objects sit close together reaches the objects that follow. The revision then wrote the form entry and the annotation onto the same object, the second silently dropping the first, producing a document with a signature dictionary and no form to reach it from. It was latent in any such document, and only a 434-byte test fixture was small enough to expose it;
- **Exceptions name the fault that actually occurred.** Fifteen of sixteen call sites reported structural faults as "Invalid file extension".

<hr>

## Breaking for implementers

Calling or injecting the contracts is unaffected: the new parameters are optional and trailing. **Implementing them is not.**

| | 2.1 | 2.2 |
| --- | --- | --- |
| `Contracts\A1PdfSign` | n/a | gains `signatureFields()` |
| `Contracts\PdfSigner::sign()` | 7 parameters | gains `?string $intoField` and `?CertificationLevel $certification` |
| `Data\SignatureReport::toArray()` | 2 keys | gains `certification` |

The last one reaches anyone asserting on the whole array, a snapshot test or a strict equality check. Reading properties and calling methods is unaffected. The [upgrade guide](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/UPGRADE.md) maps each one.

<hr>

## One verification this release does not claim

**Whether a reader enforces a certification is untested.** `pdfsig` does not surface `/DocMDP` at all, so poppler confirms only that the file is well formed and both signatures verify. Enforcement needs Adobe Reader or ITI Validar, which this project cannot run in CI.

The bytes are right and were checked by hand: `/Perms` names the signature that carries the transform, and the transform carries the permission for the level asked for. `samples/certified.pdf` exists so you can check the rest yourself. [Decision 0012](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/docs/decisions/0012-certification-signatures.md) records the gap rather than rounding it up.

<hr>

# 2.1.0

## PEM certificates

**PKCS#12 is no longer the only encoding the package reads.** A PEM certificate can be handed to the signer directly, with no `openssl pkcs12 -export` step first, which was the only answer this package had for two years ([discussion #147](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/147)).

```PHP
A1PdfSign::signFromPem('path/to/certificate.pem', 'password', 'path/to/document.pdf');

A1PdfSign::newSignature()
    ->certificatePem('path/to/certificate.crt', 'path/to/private.key', 'password')
    ->pdf('path/to/document.pdf')
    ->sign();
```

PKCS#12 is not a peer of PEM but a container that is converted into it, so the two share one pipeline: the encoding is decided at the entry point and everything after it (profiles, seals, timestamps, multiple signatures, validation) is the same code. `PemCertificateReader` implements the existing `CertificateReader` contract as the degenerate case, the reader whose conversion step is empty.

<hr>

## Added

- **`certificatePem()` and `certificateFromPem()`** on the builder, and **`signFromPem()`** on the facade and the `A1PdfSign` contract. The private key may sit in the same file as the certificate or in one of its own;
- **The encoding is read from the content, never the extension.** PEM ships as `.pem`, `.crt`, `.cer`, `.key` and `.txt`, so gating on the suffix would reject valid files;
- **An empty password is accepted**, because a PEM private key is frequently unencrypted, legal for PEM and impossible for PKCS#12. OpenSSL ignores a passphrase given for a key that does not need one;
- **`pdf:sign` takes `--key`** for the two-file form. Passing it with a PKCS#12 bundle is rejected rather than ignored: the bundle already carries its key, so the combination means the caller is mistaken about what they hold;
- **`encryptCertificate()` accepts PEM**, detecting the encoding instead of gaining a sibling: it takes "a certificate" generically, where signing keeps explicit entry points;
- **`InvalidPemContentException`**, which names the offending half: binary DER or PKCS#12 bytes handed to the PEM entry point are reported as misrouted, not as a generic parse failure;
- **`samples/certificate.pem`**, the identity `samples/certificate.pfx` already carried, in the second encoding.

<hr>

## Fixed

- **A passphrase-protected private key could not be checked against its certificate.** `CertificateParser` passed the bundle to `openssl_x509_check_private_key()` as a string, which cannot decrypt it. PKCS#12 never reached that path, since `openssl_pkcs12_read()` returns an already-decrypted key, so the defect only surfaced once PEM arrived. The array form is correct for encrypted and unencrypted keys alike, so nothing branches on it.

<hr>

## ⚠️ Breaking for implementers

Calling or injecting the contracts is unaffected. **Implementing them is not.**

| | 2.0 | 2.1 |
| --- | --- | --- |
| `Contracts\A1PdfSign` | n/a | gains `signFromPem()` |
| `Contracts\CertificateReader::read()` | `$pfxContents` | `$contents` |

The parameter was named after PKCS#12 when that was the only encoding a reader could ingest. Every call site in the package is positional, so the rename reaches you only through a named argument. The `pdf:sign` argument `pfxPath` became `certificatePath` for the same reason: positional on the command line, so only `Artisan::call()` with named keys is affected.

The [upgrade guide](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/UPGRADE.md) maps each one.

<hr>
<hr>

# 2.0.0

## 💥 Breaking Changes

### Version 2 is a clean break. The 1.x surface was removed, not deprecated.

There is no compatibility layer. Every 1.x entry point is gone, and the [Upgrade guide](/docs/2.x/upgrade-from-1x) maps each one to its replacement.

- The **global helper functions** (`signPdfFromFile()`, `signPdfFromUpload()`, `encryptCertData()`, `decryptCertData()`, `validatePdfSignature()`, `a1TempDir()`) no longer exist. Everything goes through the `A1PdfSign` facade;
- `LSNepomuceno\LaravelA1PdfSign\Sign\*` (`ManageCert`, `SignaturePdf`, `ValidatePdfSignature`, `SealImage`) was removed;
- `LSNepomuceno\LaravelA1PdfSign\Entities\*` was replaced by `Data\*`;
- String constants (`MODE_RESOURCE`, `FONT_SIZE_LARGE`, `IMAGE_DRIVER_GD`) became enums;
- Minimum requirements are now **Laravel 13** and **PHP 8.4**.

<hr>

## The reason for the rewrite

**A second signature used to destroy the first.** Signing re-imported every page through FPDI and rebuilt the document, which discarded annotations, form fields and any signature already present, the behaviour reported in [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430), open since 2021.

Version 2 signs by **appending a revision** (ISO 32000-1 §7.5.6). The original bytes survive byte for byte, so every earlier signature stays valid and each one covers the document as it stood when that signature was made.

Verified with poppler's `pdfsig` on a document carrying six signatures: all six report *Signature is Valid*.

<hr>

## Added

- **PAdES profiles**: B-B, B-T, B-LT and B-LTA, alongside the legacy ISO 32000-1 profile. See [Signature profiles](/docs/2.x/signature-profiles);
- **Real cryptographic validation.** 1.x reported a document as "validated" when the parsed subject contained a `CN` or `OU` field, which a tampered document still has. Validation now verifies the CMS against the bytes each signature covers;
- **Multiple signatures** on one document, each preserving the ones before it;
- **RFC 3161 timestamps**, embedded OCSP responses and CRLs (Document Security Store), and archive timestamps;
- **A fluent builder** as the primary API, plus contracts bound in the container so every part is swappable;
- **Publishable configuration**: temporary path, signature profile, timestamp authority, seal driver, font and background;
- `#[\SensitiveParameter]` on every password argument, so certificate passwords stop appearing in stack traces.

<hr>

## Removed

- **TCPDF and FPDI.** TCPDF 6 was discontinued by its author on 2026-05-30; the engine is now `tecnickcom/tc-lib-pdf-sign`, its official successor;
- The private key **written in plain text** to `src/Temp/` inside the consuming application's `vendor/`;
- The certificate password travelling on the command line, where `ps` exposed it to any user on the machine;
- The `openssl` binary as a hard requirement. Certificates are read through `ext-openssl`; the CLI remains only as the fallback for legacy PFX files under OpenSSL 3.x.
