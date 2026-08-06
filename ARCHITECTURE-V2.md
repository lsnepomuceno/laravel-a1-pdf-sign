# Modernization plan — v2.0

Reference document for the architectural refactor of the package. Baseline: `1.0.9`.
Status: **proposal** — no decision implemented yet, except the PoC in §3h.1.

---

## 1. Diagnosis

Real problems imposed by the current architecture — not matters of taste.

### Security / robustness

1. `ManageCert::fromPfx()` writes the **PEM containing the private key in plain text** into
   `src/Temp/` — that is, inside the consuming application's `vendor/` — and the certificate
   password travels on the command line (`-password pass:...`), visible through `ps` to any
   user on the machine.
2. Temp files are not removed on exception paths. `File::delete()` always comes after code
   that can throw; the test `tearDown()` only masks the leak.
3. `a1TempDir()` requires the package directory to be writable, with a silent fallback to
   `sys_get_temp_dir()` — non-deterministic behaviour across environments.

### Coupling / testability

4. The public API is six global functions in `autoload.files`. No namespace, not mockable,
   no class autocompletion in the IDE, and any signature change is breaking.
5. Zero contracts, zero container bindings, zero facade. The `ServiceProvider` only registers
   two commands. Nothing is swappable by the consumer.
6. `ManageCert` carries three responsibilities: PKCS#12 conversion, x509 parsing/validation
   and blob encryption. `makeDebugCertificate()` — test code — lives in a production class.

### Design

7. String constants (`MODE_RESOURCE`, `FONT_SIZE_LARGE`, `IMAGE_DRIVER_GD`) where PHP 8.1+
   offers enums.
8. `SignaturePdf` mixes *producing* and *delivering*: `signature()` calls `File::put()` and
   immediately `File::get()` back in `MODE_RESOURCE` — a pointless disk round-trip, since
   `TCPDF::output(..., 'S')` already returns the complete string.
9. `ValidatePdfSignature` extracts the PKCS#7 blob with a `ByteRange` regex and then does
   *text parsing* of `openssl pkcs7 -print_certs` output through three chained
   `preg_replace` calls. Fragile and coupled to the binary's output format.
10. No publishable config — nothing is configurable: temp disk, image driver, default seal,
    legacy flag.

### Hygiene

11. `tecnickcom/tc-lib-pdf: ^8` sits in `composer.json` and is **used nowhere** in `src/` or
    `tests/`. ⚠️ **Do not remove** — see §3g: this "dead" dependency is precisely the
    official successor to TCPDF, and becomes the v2 engine.
12. **The PDF engine is officially deprecated.** `tecnickcom/tcpdf` 6 was discontinued by its
    author on 2026-05-30. See §3g — the highest-impact change in this plan.
13. No Pint, no PHPStan. `CONTRIBUTING.md` still asks for PSR-2 (deprecated since 2019).

### Defects found while executing the plan

14. **`encryptCertData()` and `decryptCertData()` do not round-trip.** Discovered in PR 5.
    `encryptCertData()` stores `$cert->getCert()->original`, the PEM produced by
    `openssl pkcs12 -nodes`. `decryptCertData()` writes that PEM to a `.pfx` file and feeds
    it to `openssl pkcs12 -in`, which expects binary PKCS#12 and fails:

    ```
    asn1 encoding routines:asn1_check_tlen:wrong tag ... Type=PKCS12
    ```

    Half of the certificate-storage API has therefore never worked. It went unnoticed because
    the v1 suite covered `encryptCertData()` only, asserting the shape of its return value and
    never reading it back.

    The fix belongs to **PR 6**: `ManageCert` needs a path that ingests already-decrypted PEM
    content instead of shelling out to `pkcs12`, which also removes a temp file and a process
    spawn. It carries a compatibility question that needs an explicit decision — the
    `$isBase64` flag suggests some callers may be storing the raw PFX binary rather than the
    PEM, and those callers are served correctly by the current code path.

    A skipped test in `tests/ServiceTest.php` documents the defect so it stays visible on
    every run rather than living only in this document.

---

## 2. Proposed architecture

**The root namespace `LSNepomuceno\LaravelA1PdfSign` stays** — renaming would be a gratuitous
break. The reorganization happens in the sub-namespaces.

```
src/
├── A1PdfSignServiceProvider.php
├── Facades/A1PdfSign.php
├── Contracts/
│   ├── CertificateReader.php        # PFX → Certificate
│   ├── PdfSigner.php                # Certificate + PDF → SignedPdf
│   ├── SignatureValidator.php       # PDF → SignatureReport
│   └── SealRenderer.php             # Certificate → seal bytes
├── Enums/
│   ├── FontSize.php
│   ├── ImageDriver.php
│   └── SealPage.php                 # First | Last | Every | Number(int)
├── Data/                            # readonly value objects
│   ├── Certificate.php
│   ├── EncryptedCertificate.php
│   ├── SignatureInfo.php            # name / location / reason / contact
│   ├── SealPlacement.php            # x, y, w, h, page
│   ├── SignedPdf.php                # ← signing output
│   └── SignatureReport.php          # ← validation output
├── Certificates/
│   ├── NativeCertificateReader.php      # ext-openssl (default)
│   ├── OpenSslCliCertificateReader.php  # legacy fallback
│   └── CertificateVault.php             # blob encrypt / decrypt
├── Signing/
│   ├── TcLibPdfSigner.php           # default — LTV, TSA, native import (§3g)
│   ├── TcpdfSigner.php              # legacy driver, optional deps
│   └── PendingSignature.php         # fluent builder
├── Validation/
│   ├── PdfSignatureExtractor.php    # ByteRange → DER
│   └── Pkcs7Reader.php              # DER → certificates
├── Seal/InterventionSealRenderer.php
├── Support/TemporaryFile.php        # guaranteed cleanup through finally
├── Exceptions/
├── Console/
└── Testing/DebugCertificate.php     # moved out of ManageCert
config/a1-pdf-sign.php
resources/{font,img}/                # moved out of src/Resources
```

### Target public API

```php
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signed = A1PdfSign::certificate($pfxPath, $password)   // or ::certificateFromUpload($file)
    ->pdf($pdfPath)
    ->info(name: 'Lucas', reason: 'Contract')
    ->seal()                                            // uses config defaults
    ->sign();                                           // → SignedPdf

$signed->contents();          // string
$signed->save($path);         // string (path)
$signed->download('doc.pdf'); // BinaryFileResponse
$signed->toResponse();        // inline
```

The core gain: **`sign()` no longer decides transport**. The `MODE_*` enum disappears, the
disk round-trip disappears, and the same result can be consumed in several ways.

Capabilities unlocked by the new engine (§3g, §3h), all opt-in with config defaults:

```php
A1PdfSign::certificate($pfx, $pass)
    ->pdf($signedPdfPath)
    ->approval()                          // approval signature (incremental update):
                                          // preserves previous ones instead of overwriting
    ->timestamp('https://tsa.example.com') // RFC 3161 timestamp
    ->ltv()                               // DSS/VRI + embedded OCSP/CRL
    ->sign();

A1PdfSign::certificate($pfx, $pass)
    ->pdf($path)
    ->certify(CertLevel::NoChanges)       // certification — only one per document
    ->sign();
```

The **certification vs approval** distinction stops being implicit. Today the package always
emits a certification signature (which is why the second one overwrites the first —
TCPDF#430); in v2 it becomes an explicit choice, with `approval()` as the path to multiple
signatures.

Symmetric validation:

```php
$report = A1PdfSign::validate($pdfPath);   // SignatureReport
$report->isValid();
$report->signers();                        // Collection<Signer>
```

### Publishable config

```php
return [
    'temp_disk' => env('A1_PDF_SIGN_DISK'),   // null = sys_get_temp_dir()

    'signer' => 'tc-lib-pdf',                  // tc-lib-pdf | legacy  (§3g)

    'signature' => [
        'timestamp' => ['url' => env('A1_TSA_URL'), 'user' => null, 'password' => null],
        'ltv' => ['enabled' => false, 'embed_ocsp' => true, 'embed_crl' => true],
    ],

    'certificate' => [
        'reader' => 'native',                  // native | cli | auto
        'legacy' => false,                     // legacy provider / -legacy flag
        'use_path_env' => false,
    ],

    'seal' => [
        'driver' => 'gd',
        'font' => ['path' => null, 'size' => 'large', 'color' => '#16A085'],
        'background' => null,
        'placement' => ['x' => 155, 'y' => 250, 'width' => 50, 'page' => 'last'],
    ],
];
```

---

## 3. Technical decisions and trade-offs

### a) Replace the shell-out with `ext-openssl` — with a caveat

`openssl_pkcs12_read()` handles the PFX→PEM conversion natively: it removes the password from
`ps`, removes the private key written to disk, and kills the dependency on having the
`openssl` binary in `PATH` — along with the whole `$usePathEnv` complication.

**The caveat:** under OpenSSL 3.x, `openssl_pkcs12_read()` **fails** on old PFX files
(RC2/40-bit) and PHP exposes no equivalent to the CLI's `-legacy`. The only fix is enabling
the legacy provider in `openssl.cnf`, which is server configuration, not package code.

So the CLI **cannot be removed** — it is demoted to a fallback driver behind the
`CertificateReader` contract, with an `auto` mode that tries native first and falls back to
the CLI. This keeps `setIsLegacy()` working while making the majority of cases stop touching
disk and `proc_open`.

### b) PKCS#7 parsing

There is no clean native replacement: `openssl_pkcs7_verify()` needs the signed message
reassembled, which is fragile for a detached PDF signature. Two options:

- **(i) Keep the CLI** encapsulated in `Pkcs7Reader`, but parse the certificates with
  `openssl_x509_parse()` instead of the three `preg_replace` calls. No new dependency,
  immediate robustness gain. **← recommended for v2.0.**
- **(ii)** Add `phpseclib/phpseclib ^3` and decode the CMS through ASN.1 — removes the CLI
  entirely and opens the way to *actually verifying* the signature. New dependency, more
  work. **← v2.1, behind a config flag.**

> **Honest note:** the current method **does not verify the signature cryptographically** —
> it only extracts metadata; the `validated` field merely checks whether `OU` or `CN` exists
> in the subject. The v2 naming (`SignatureReport::isValid()`) must reflect that limitation
> until option (ii) exists.

### c) Temp files

`TemporaryFile` with guaranteed `try/finally` and `__destruct` as a safety net, writing to
`sys_get_temp_dir()` or a configurable disk — never inside the package. `src/Temp/` ceases to
exist.

### d) In-memory seal

`SealRenderer` returns bytes, removing the intermediate file between generating the seal and
stamping it.
*(To be confirmed during implementation — the imaging bridge may impose restrictions.)*

### e) PHP and Laravel floor

The floor is not an aesthetic choice — the tooling imposes it. Packagist survey on
**2026-08-05**:

| Runtime / tool | Minimum PHP |
|---|---|
| Laravel 13 (`v13.24`) | `^8.3` |
| `orchestra/testbench` 11 (the L13 channel) | `^8.3` |
| Pest 4 | `^8.3` |
| Pest 5 + plugins (`arch`, `type-coverage`, `laravel`, `drift`, `mutate`) | `^8.4` |
| Infection `0.34` | `^8.3` |
| `roave/backward-compatibility-check` 8 | `~8.4 \|\| ~8.5` |
| Larastan 3 | `^8.2` |
| PHPStan 2, Pint, Rector, `composer-dependency-analyser` | ≤ 8.2 |

### e.1) What is the Laravel floor? — the answer does not come from PHP 8.3

Official Laravel support policy (`laravel.com/docs/13.x/releases`, retrieved 2026-08-05):

| Laravel | Supported PHP | Release | Bug fixes until | Security until | Status today |
|---|---|---|---|---|---|
| 10 | 8.1 – 8.3 | 2023-02-14 | 2024-08-06 | 2025-02-04 | **EOL** |
| 11 | 8.2 – 8.4 | 2024-03-12 | 2025-09-03 | 2026-03-12 | **EOL** |
| 12 | 8.2 – 8.5 | 2025-02-24 | 2026-08-13 | 2027-02-24 | active |
| 13 | 8.3 – 8.5 | 2026-03-17 | Q3 2027 | 2028-03-17 | active |

**The PHP 8.3 floor required by Laravel 13 excludes no Laravel version** — 8.3 sits inside
the range supported by L10, L11, L12 and L13. It is not the limiting factor.

What determines the Laravel floor is the **ceiling**, not the floor: since we want to support
**PHP 8.5**, only versions that reach it qualify. L10 stops at 8.3 and L11 stops at 8.4 —
neither can be tested on PHP 8.5. Only **L12 and L13 cover PHP 8.5**.

Two independent criteria converge on the same point: L12 and L13 are also the only two
versions still under security support. Therefore:

> **Floor: Laravel 12.** Not because of PHP 8.3, but because it is the oldest version that
> reaches PHP 8.5 — and, as it happens, the oldest one still alive.

### e.2) What is the PHP floor?

PHP lifecycle (`php.net/supported-versions.php`, retrieved 2026-08-05):

| Branch | Active support until | Security until |
|---|---|---|
| 8.2 | 2024-12-31 | 2026-12-31 |
| 8.3 | 2025-12-31 | 2027-12-31 |
| 8.4 | 2026-12-31 | 2028-12-31 |
| 8.5 | 2027-12-31 | 2029-12-31 |

With Laravel 12–13 fixed, the possible intersection is **PHP 8.3 – 8.5**. Two options remain:

| | Matrix | Toolchain | Language features |
|---|---|---|---|
| **8.3 floor** | 6 jobs | Pest 4 on the 8.3 jobs, Pest 5 on 8.4/8.5 — **split stack** | no property hooks, no `private(set)`, no native `#[\Deprecated]` |
| **8.4 floor** | 4 jobs | Pest 5 on **every** job, Roave and type-coverage everywhere | property hooks, `private(set)`, `#[\Deprecated]` |

**Recommendation: PHP 8.4 floor.** 8.3 is defensible on lifecycle grounds (security until
Dec 2027), but it forces keeping two Pest majors in parallel for the rest of v2 — and 8.4 is
exactly what brings the features the proposed architecture uses (§6.6). Final matrix, with no
exclusions:

| | Laravel 12 | Laravel 13 |
|---|:---:|:---:|
| **PHP 8.4** | ✓ | ✓ |
| **PHP 8.5** | ✓ | ✓ |

**4 jobs against the current 11**, and every cell under active support from both vendors.
`composer.json` constraint: `">=8.4 <8.6"` — today it reads `">=8.1 <8.5"`, which **actively
blocks PHP 8.5**.

### f) ~~Remove `tecnickcom/tc-lib-pdf`~~ — **reverted**

The observation that the dependency is declared and unused was right; the conclusion was
wrong. `tc-lib-pdf` is the official successor to TCPDF and becomes the v2 engine (§3g). What
leaves is `tecnickcom/tcpdf` — and, with it, `setasign/fpdi`.

### g) PDF engine: TCPDF → tc-lib-pdf

**This is the highest-impact item in the plan.** It is not a library swap — it changes the
core signing flow and unlocks the feature requested in
[TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430), opened by this project in
September 2021.

#### The trigger

On **2026-05-30**, Nicola Asuni (`nicolaasuni`, author of TCPDF) closed #430 with:

> TCPDF 6 is now a legacy project and is officially **deprecated**, so no further fixes or
> features will be merged into this repository. […] Please migrate to **tc-lib-pdf**.

The pinned announcement ([TCPDF#867](https://github.com/tecnickcom/TCPDF/issues/867))
confirms it: TCPDF 6 is a monolithic ~30,000-line file with no future maintenance. A TCPDF 7
is planned as a compatibility *wrapper* over tc-lib-pdf, with **no timeline** — not something
worth waiting for.

#### What tc-lib-pdf actually delivers

The maintainer's comment is a generic closing message — it does **not** claim that
tc-lib-pdf solves LTV or multiple signatures. Verified directly against the code in
`vendor/tecnickcom/tc-lib-pdf` **8.0.85**, already installed in this repository:

| Capability | Evidence in the source |
|---|---|
| **LTV** | `setSignature(['ltv' => [...]])` with `enabled`, `embed_ocsp`, `embed_crl`, `embed_certs`, `include_dss`, `include_vri` — `src/Tcpdf.php:442-471` |
| **DSS / VRI** | writes `/DSS` into the catalog — `src/Output.php:937`; "Collect deterministic validation material for LTV embedding" — `src/Output.php:4157` |
| ~~Multiple signatures~~ | ❌ **Not delivered** — see §3h. The `approval` flag is semantic; it does not implement incremental update |
| **Timestamping (TSA)** | `setSignTimeStamp()`, `applySignatureTimestamp()`, `requestTimestampToken()`, `postTimestampRequest()` — a real RFC 3161 client |
| **Certification vs approval** | `cert_type` 1/2/3 + `/DocMDP` — `src/Output.php:1059` |
| **Native PDF import** | `Import\Importer`: `setImportSourceFile()`, `setImportSourceData()`, `getSourcePageCount()`, `importPage()`, `importPages()` |
| **Key in memory** | `privkey` / `signcert` accept a **PEM string** or `file://` — no need to write the key to disk |

LTV, TSA and native import check out. **Multiple signatures do not** — see §3h.

#### Why this is structural, not a swap

The current flow (`SignaturePdf::signature()`) **rebuilds** the document: it imports every
page of the source PDF into a brand-new document through FPDI and signs the result. Multiple
signatures require the opposite — *incremental update*, which **appends** to the existing
file without rewriting it.

The two models are incompatible. That is why #430 never had a solution inside the current
architecture: it is not a TCPDF limitation, it is a limitation of the "re-import everything"
approach. Rebuilding the PDF also silently destroys previous signatures, annotations and form
fields from the original — a latent bug nobody reported because the dominant use case is
signing once.

#### Dependency impact

| Package | Before | After |
|---|---|---|
| `tecnickcom/tc-lib-pdf` | declared, unused | **main engine** |
| `tecnickcom/tcpdf` | engine (through FPDI) | `suggest` — legacy driver only |
| `setasign/fpdi` | page import | **removed** — `Import\Importer` is native |

Net: two fewer dependencies in the default install, and the one that was "spare" becomes the
main one. `tc-lib-pdf` requires `php >= 8.1`, compatible with the proposed floor.

#### Migration strategy

The `PdfSigner` contract (§2) stops being a speculative abstraction and gains two real
implementations:

- **`TcLibPdfSigner`** — the v2 default. LTV, TSA, native import.
- **`TcpdfSigner`** — legacy driver (FPDI + TCPDF 6), for byte-for-byte fidelity with v1.
  `tecnickcom/tcpdf` and `setasign/fpdi` become optional; the driver throws a clear exception
  if those classes are absent.

The BC shims (§4) route to the **new driver** by default — the public API is preserved, the
engine changes. Anyone needing output identical to v1 selects `signer => 'legacy'` in config
and installs the optional dependencies. This goes into `UPGRADE.md`.

#### g.1) PoC result — and a version gap that changes the argument

PR 0 has been executed. Code and full report in **`poc/tc-lib-pdf-ltv-tsa/`**.
**15/15 checks pass**, including a live RFC 3161 round-trip against a public TSA that grew
the embedded CMS from 1476 to 6135 bytes. LTV emits `/DSS`, `/VRI` and `/Certs`; PEM strings
are accepted for `privkey`/`signcert`, confirming the "key in memory" claim.

**But the table above was read against the wrong version.** The lock file pinned
**8.0.85**; the current release is **8.67.2** — 67 minor versions of drift. On the current
release the upgrade also pulls in **`tecnickcom/tc-lib-pdf-sign` 1.1.1** transitively, which
did not exist at 8.0.85 and provides the full PAdES ladder:

| Profile | `/SubFilter` | Adds |
|---|---|---|
| Legacy | `adbe.pkcs7.detached` | ISO 32000-1 detached CMS with ESS `signing-certificate-v2` |
| PAdES B-B | `ETSI.CAdES.detached` | CAdES signed attributes |
| PAdES B-T | `ETSI.CAdES.detached` | B-B + RFC 3161 signature timestamp |
| PAdES B-LT | `ETSI.CAdES.detached` | B-T + `/DSS` and `/VRI` validation material |
| PAdES B-LTA | + `ETSI.RFC3161` | B-LT + `/Type /DocTimeStamp` archive timestamp |

Upstream reports these validated against the EU DSS reference validator. **That, not plain
LTV, is the real argument for the migration** — and it matters disproportionately for this
package's audience, since PAdES B-LT/B-LTA is what long-term legal validity actually
requires.

Consequence for PR 1: the floor bump must be accompanied by
`composer update tecnickcom/tc-lib-pdf`, and the constraint reviewed — `^8` silently spans
8.0 to 8.67.

> **Still not verified:** no external reader (Adobe / ITI Validar) was used; checks are
> structural plus one TSA round-trip. OCSP and CRL were disabled because the self-signed test
> certificate has neither endpoint, so only certificate embedding in the DSS is exercised.
> B-LTA was not exercised end to end.

#### g.2) Fonts — an unplanned blocker

**tc-lib-pdf cannot emit any PDF without a generated font definition**, not even a
signature-only document containing no text:

```
Com\Tecnick\Pdf\Font\Exception: unable to read file: helvetica.json
```

A plain `composer install` ships none — the font data is built by `make fonts` upstream.
TCPDF 6 bundles 165 fonts, but in PHP format, while tc-lib-pdf-font expects JSON. Not
interchangeable.

Path proven in the PoC: convert the core-14 AFM metrics that `tecnickcom/tc-font-mirror`
ships.

```bash
php vendor/tecnickcom/tc-lib-pdf-font/util/convert.php -i Helvetica.afm -t Core -o resources/fonts
```

`-t Core` matters: `-t Type1` demands a binary `.pfb`, which the mirror does not carry for
the core family. **PR 7 must ship the generated JSON under `resources/fonts/` and define
`K_PATH_FONTS` from the service provider**, so consumers never encounter this. That is new
scope the plan did not account for.

### h) Multiple signatures: our own incremental update

> **Correction.** An earlier version of this document claimed tc-lib-pdf delivered
> multi-signature through the `approval` flag. That was wrong. The text below replaces that
> analysis.

#### Why `approval` does not solve it

Tracing the three — and only — usages of `signature['approval']` in tc-lib-pdf
(`Output.php:1023`, `1055`, `5113`), the flag does exactly one thing: it **suppresses** the
`/Reference << /Type /SigRef … /DocMDP >>` dictionary and the corresponding `/Perms` entries.
In other words, it toggles between a **certification** and an **approval** signature — ISO
32000-1 semantics, nothing more.

Nowhere does it read the original PDF's bytes to append a revision: `$startxref =
strlen($out)` (`Output.php:630`) is computed over the freshly built output, and the file's
only `/Prev` belongs to *outlines*, not to a cross-reference table. `Import\Importer`
confirms the model: `importPage()` allocates a **Form XObject**, clones resources through
`ResourceCloner` and extracts the content stream — architecture identical to FPDI's.
**That is rebuilding, not appending.**

The decisive proof is in this package itself: `SignaturePdf.php:202` **already passes `'A'`**
as the 7th argument to TCPDF 6's `setSignature()`, and has for years. Approval mode has been
on the whole time — and the second signature still overwrites the first. The flag was never
the missing piece; the document rebuild is.

#### What is actually required

*Incremental update* (ISO 32000-1 §7.5.6): keep the original bytes **untouched** and append a
new revision containing only the changed objects:

1. Read the original's xref/trailer → previous `startxref`, `/Root`, `/Size`, `/AcroForm`.
2. Append: a `/Type /Sig` object (with `/ByteRange` and a fixed-size `/Contents`
   placeholder), the signature field widget, the updated page (new `/Annots`), the updated
   `/AcroForm` (`/Fields` + `/SigFlags 3`) and the catalog.
3. A new cross-reference section with `/Prev <previous startxref>`, a new `startxref`,
   `%%EOF`.
4. Compute `/ByteRange [0 a b c]` covering the **whole** file except the placeholder, sign it
   with detached PKCS#7 and inject the blob without shifting a single offset.

Each signature covers the entire file up to its own revision — that is how a reader shows
"signature 1 valid, covers revision 1" without invalidating it.

#### Complementarity — no single library solves it

| | Incremental update | Multiple signatures | LTV / DSS | TSA |
|---|:---:|:---:|:---:|:---:|
| TCPDF 6 (current) | ❌ | ❌ | ❌ | ❌ |
| tc-lib-pdf | ❌ | ❌ | ✅ | ✅ |
| `ddn/sapp` | ✅ | ✅ | ❌ | ❌ (planned) |

v2 takes **both halves**: the tc-lib-pdf engine for generation, seal, LTV and timestamping;
our own incremental writer for multi-signature. That combination is what neither dependency
delivers alone — and becomes the package's differentiator.

#### ⚠️ Licensing — a non-negotiable constraint

**`ddn/sapp` is LGPL-3.0-or-later; this package is MIT.**

- **Porting or adapting SAPP code into `src/` is a licence violation.** An adapted excerpt is
  still a derivative work and would drag the entire package into LGPL.
- **Studying the technique is legitimate.** Algorithms and file-format mechanics are not
  protected by copyright, and incremental update is specified in ISO 32000-1 §7.5.6 and
  §12.8 — a public standard.

Therefore: **clean-room implementation, written from the specification.** SAPP serves to
understand *what* must be written into the file; the *how* comes from the standard and from
our own code. In practice that means keeping ISO 32000-1 open during the PR, not
`vendor/ddn/sapp`.

**Settled decision: SAPP is never taken as a dependency** — not in `require`, not in
`require-dev`, not as `suggest`. Depending on it would be legal (LGPL allows library use
without contaminating the consumer), but it is ruled out: it is a legacy project, and we
would inherit its maintenance along with it. The use is strictly **conceptual** — understand
the technique and write ours from the standard.

This is verifiable in CI, not merely an intention — an arch test enforces the rule:

```php
arch('no trace of SAPP')
    ->expect('ddn\Sapp')->not->toBeUsed();
```

And `composer-dependency-analyser` (§6.1) fails if the package shows up in `composer.json`.

#### Where this lands in the architecture

```
src/Signing/
├── TcLibIncrementalSigner.php   # the ONLY class extending Com\Tecnick\Pdf\Tcpdf.
│                                # Coupling boundary (§3h) — implements PdfSigner
├── TcpdfSigner.php              # legacy, optional deps
├── PendingSignature.php         # fluent builder
└── Incremental/                 # 100% ours, clean-room from ISO 32000-1
    ├── DocumentReader.php       # xref/trailer/catalog/AcroForm via tc-lib-pdf-parser
    ├── RevisionWriter.php       # changed objects + xref with /Prev + startxref
    └── ByteRangeCalculator.php  # offsets and fixed-size placeholder
```

`Incremental/` knows nothing about tc-lib-pdf or SAPP: it takes bytes and returns bytes. It
is independently testable and is where the standard's algorithm lives.

#### What we reuse from tc-lib-pdf — verified

The rule is: **we only write what does not exist** (revision appending). Everything else
comes from tc-lib-pdf. Survey of the installed code:

| Piece | Source | Status |
|---|---|---|
| Parsing the original PDF | `tc-lib-pdf-parser` → `Parser::parse(string $data): array` | ✅ already a transitive dependency. SAPP had to write its own parser; we do not |
| LTV material collection | `Output::collectValidationMaterial()` | ✅ `protected` |
| OCSP | `buildOcspRequest()`, `postOcspRequest()` | ✅ `protected` |
| CRL | `getCrlData()` | ✅ `protected` |
| RFC 3161 timestamping | `buildTimestampRequest()`, `postTimestampRequest()`, `requestTimestampToken()`, `applySignatureTimestamp()` | ✅ `protected` |
| `/Sig` dictionary, DocMDP, `/Reference` | `getOutSignature()`, `getOutSignatureDocMDP()`, `getOutSignatureInfo()` | ✅ `protected` |
| Placeholder and ByteRange | `Base::BYTERANGE`, `Base::SIGMAXLEN` (= 11742) | ✅ `protected const` |
| Visual seal appearance (Form XObject) | `tc-lib-pdf-graph`, `-image`, `-font` | ✅ public |
| Detached CMS | `openssl_pkcs7_sign()` with `PKCS7_DETACHED \| PKCS7_BINARY` | ✅ native, no shell-out (§3a) |
| Revision appending / xref `/Prev` | `appendIncrementalRevision()`, `buildIncrementalXref()`, `buildIncrementalTrailer()`, `previousStartxref()` | ⚠️ `protected`, **added after 8.0.85** — see the note below |
| **Signing an externally supplied PDF** | — | ❌ **does not exist: this is what we write** |

> **Revised after PR 0.** The original survey ran against tc-lib-pdf 8.0.85 and concluded
> that no incremental machinery existed. On **8.67.2** it does — `appendIncrementalRevision()`
> and friends back the post-signing `/DSS` (B-LT) and `/DocTimeStamp` (B-LTA) revisions.
>
> This does **not** make PoC 0b redundant: there is still no public API that signs an
> *externally supplied* PDF, which is precisely this package's core use case. What changes is
> the build-versus-reuse call — **PR 7b must first attempt to drive
> `appendIncrementalRevision()` from the adapter class**, and only fall back to the PoC 0b
> writer if the inherited path cannot accept foreign document bytes. That decision belongs to
> PR 7b, on evidence, not to this document.

`Com\Tecnick\Pdf\Tcpdf` is a concrete **non-final** class, and every member above is
`protected` — so inheriting grants legitimate access to all of it:

```php
final class TcLibIncrementalSigner extends \Com\Tecnick\Pdf\Tcpdf implements PdfSigner
{
    // inherits collectValidationMaterial(), applySignatureTimestamp(),
    // buildOcspRequest(), SIGMAXLEN, BYTERANGE…
    // adds only the incremental revision writing
}
```

In practice this reduces the new work to **one** component: the revision writer. LTV, TSA,
OCSP, CRL and the seal appearance are all reuse.

> **Trade-off — coupling to internal API.** `protected` is not public API: tc-lib-pdf may
> change those members in a *minor* release without treating it as a break. The Roave BC
> check (§6.1) does not protect here, because it inspects **our** API, not theirs.
> Mitigations: confine the inheritance to a **single** adapter class behind the `PdfSigner`
> contract, cover each inherited method with a test that fails loudly if the signature
> changes, and pin a conservative constraint instead of an open `^8`.

A valuable side effect: signing by appending preserves the original bytes on the **first**
signature too. That permanently removes the latent bug from §3g — rebuilding the PDF destroys
annotations, form fields and structure from the original. `IncrementalSigner` therefore
becomes the default path, not just the path for the second signature.

#### h.1) PoC result — hypothesis confirmed

PR 0b has been executed. Code and full report in **`poc/incremental-signature/`**.

```
original: 9061 bytes
signature 1: 9061  -> 26313 bytes | prefix intact: YES | ByteRange[0 9190  25576 737] VALID
signature 2: 26313 -> 43580 bytes | prefix intact: YES | ByteRange[0 26442 42828 752] VALID
signature 3: 43580 -> 60861 bytes | prefix intact: YES | ByteRange[0 43709 60095 766] VALID

/Prev chain: 8605 -> 26138 -> 43404        3 /Sig objects, 3 byte ranges
RESULT: 3/3 signatures valid
```

Each signature covers exactly its own revision (26313, 43580 and 60861 bytes) — the correct
semantics from the standard. Result **identical** on PHP 8.1.34 (host) and 8.4.24 (container).

Confirmed: multi-signature without overwriting, original bytes preserved, correct `/Prev`
chain, detached PKCS#7 through `openssl_pkcs7_sign`, and a certificate generated with no
shell-out — incidentally validating decision §3a.

**Not** confirmed, and therefore still work for PR 7b: validation in a real reader (Adobe /
ITI Validar), PDF 1.5+ cross-reference streams, encrypted PDFs, a complex pre-existing
`/AcroForm`, the visual seal, and LTV/TSA.

Two bugs from the spike become mandatory test cases in the production implementation:

1. **Wrong `/ByteRange`.** An already-signed document has several; using the *first* makes the
   new signature overwrite a previous signature's `/Contents` — it must be the **last**.
2. **Finding the end of the DER with `rtrim($hex, '0')`.** That cuts legitimate `0x00` bytes.
   The real length comes from the ASN.1 header.

#### Concrete risks

| Risk | Mitigation |
|---|---|
| Classic xref **and** xref streams (PDF 1.5+) with object streams | Support both; the parser already distinguishes them. Test with PDFs from varied producers |
| Encrypted PDF | Detect `/Encrypt` and fail with a clear exception — do not guess |
| Undersized `/Contents` placeholder | tc-lib-pdf uses `SIGMAXLEN = 11742`. With LTV (OCSP + CRL + embedded chain) the CMS grows a lot — size it by measurement rather than inheriting the value, and fail loudly if it does not fit |
| Coupling to tc-lib-pdf `protected` members | Inheritance confined to one adapter class; one test per inherited method (see §3h) |
| Linearized PDF | Appending invalidates linearization; acceptable and standard, but document it |
| Previous signature invalidated by accident | Mandatory regression test: sign 3×, validate all 3 in an external reader |

---

## 4. Backward compatibility — v2 is a clean break

> **Revised during PR 5.** The original plan kept a full deprecation layer until 3.0. That is
> reversed: **deprecated API is removed in 2.0, not carried.** A 3.0 is far enough out that a
> shim living until then is a shim maintained indefinitely, and every one of them constrains
> the design it wraps — `Entities\*` cannot be `final`, enums carry legacy backing values,
> helpers keep the global namespace occupied.

`^1` stays maintained on the `v1.x-dev` branch, fixes only. In v2:

- The six global helpers are **removed**. `A1PdfSign` — the facade or the injected contract —
  replaces all of them.
- `Entities\*` is **removed**. `Data\*` is the only namespace for value objects, which lets
  them be `final readonly`.
- The legacy string constants (`SealImage::FONT_SIZE_*`, `IMAGE_DRIVER_*`,
  `SignaturePdf::MODE_*`) are **removed** in favour of the enums, whose backing values are
  free to be idiomatic (`large`, `resource`) instead of mirroring the old constants.
- `Sign\ManageCert`, `Sign\SignaturePdf`, `Sign\ValidatePdfSignature` and `Sign\SealImage`
  are replaced outright by the new engine in PRs 6-9 rather than proxied.

**Consequence:** upgrading from `^1` to `^2` requires a code change. That is already true —
v2 demands PHP 8.4 and Laravel 12, so nobody upgrades without touching their project — and
`UPGRADE.md` carries the full mapping table. Anyone who cannot move stays on `^1`.

**Rollout:** the removals land alongside the PR that rewrites each area, not in one sweep,
so no PR is left half-migrated. `Entities\*` and the constants go in PR 5b; the global
helpers go in PR 6, once `ManageCert` and `ValidatePdfSignature` stop calling `a1TempDir()`
and `runCliCommandProcesses()` internally.

---

## 5. Roadmap

Independent PRs on the `v2.x-dev` branch.

| # | PR | Scope | Risk |
|---|---|---|---|
| 0 | ✅ **tc-lib-pdf PoC** | **done** — 15/15 checks, live TSA round-trip. See `poc/tc-lib-pdf-ltv-tsa/` and §3g.1 | — |
| 0b | ✅ **Incremental update PoC** | **done** — 3/3 signatures valid. See `poc/incremental-signature/` and §3h.1 | — |
| 1 | ✅ PHP/Laravel floor | **done** — `">=8.4 <8.6"` / L12+, 4-job matrix, tc-lib-pdf `^8.67`, `.gitattributes`, PHP 8.4 nullable fixes. Suite green on 8.4 and 8.5 (21 passed) | — |
| 2 | ✅ Formatting + static analysis | **done** — Pint (PER-CS), PHPStan `level: max` + Larastan + strict/deprecation rules, **216-error baseline**, `quality` job, `composer check`, `CONTRIBUTING.md` | — |
| 3 | ✅ PHPUnit → Pest | **done** — Pest 5, `tests/Pest.php`, named datasets, **arch tests**, type coverage 94.3% gated in CI. `drift` tried and discarded (§6.4) | — |
| 4 | ✅ Data + Enums | **done** — `Data/` readonly VOs, `Enums/` carrying behaviour, `Entities\*` as deprecated subclasses, `#[\SensitiveParameter]` on passwords, type coverage 95.7%. `SignatureInfo`, `SealPlacement` and `SignedPdf` deferred to PR 7, where they gain consumers | — |
| 5 | ✅ Package infrastructure | **done** — publishable config, `Contracts\A1PdfSign` bound as a singleton, facade, helpers delegating to the container, 4 more arch rules, **type coverage 100%**. Found defect §1.14. The finer-grained contracts land with their implementations in PRs 6-9 | — |
| 5b | Remove deprecated API, part 1 | drop `Entities\*` and the legacy string constants, make `Data\*` final, idiomatic enum backing values (§4) | low |
| 6 | Certificates | `NativeCertificateReader` + CLI fallback, `CertificateVault`, `TemporaryFile`, `DebugCertificate` moved to `Testing/`, fix §1.14, **drop the six global helpers** (§4) | **high** |
| 7 | Signing | `TcLibPdfSigner` (default) + `TcpdfSigner` (legacy, optional deps) + `PendingSignature` + `SignedPdf`, drop FPDI, end the disk round-trip, **ship generated core fonts + `K_PATH_FONTS` (§3g.2)** | **high** |
| 7b | Multi-signature | first try inheriting `appendIncrementalRevision()`; fall back to `Incremental/*` from PoC 0b (§3h). `approval()` / `certify()` / `timestamp()` / `ltv()` — closes TCPDF#430 | **high** |
| 7c | PAdES profiles | expose B-B / B-T / B-LT / B-LTA (§3g.1) — the strongest new capability for the package's audience | medium |
| 8 | Seal | `SealRenderer` rewritten on the tc-lib-pdf API, in-memory seal, font/colour/background config | medium |
| 9 | Validation | `PdfSignatureExtractor` + `Pkcs7Reader` with `openssl_x509_parse` | medium |
| 10 | Mutation | `pest --mutate` over `Certificates/` and `Validation/`, `--covered-min` in CI | low |
| 11 | BC + commands | shims with `#[\Deprecated]`, commands on the new API, Roave BC check, `UPGRADE.md`, docs | low |

**PR 0 comes before everything else** and is a throwaway proof of concept, not production
code. It answers the one question that could invalidate the plan: does tc-lib-pdf deliver LTV
and timestamping in practice, and not just in a docblock? Until that answer exists, PRs 7 and
8 are speculation.

PRs 1–4 can land immediately without touching behaviour — and **order matters**: the
toolchain (2–3) precedes the refactor so that arch tests, PHPStan and mutation act as a
safety net for PRs 5–9 rather than as a post-hoc audit.

PR 6 needs the most care. Before it, freeze the current suite as a baseline — including a
case with a real legacy PFX, which the tests do not have today.

PR 10 comes after the refactor on purpose: running mutation over the legacy code would yield
a low score for structural reasons, not for lack of tests.

---

## 6. Quality toolchain

Today the project has **no** quality tooling at all: no formatter, no static analysis, no
coverage (CI runs with `coverage: none`), no dependency checking, no compatibility checking.
v2 establishes the stack below.

### 6.1 Composition

| Layer | Tool | CI gate |
|---|---|---|
| Formatting | `laravel/pint` (`per` preset — PER-CS, successor to PSR-12) | `pint --test` |
| Static analysis | `phpstan/phpstan` 2 + `larastan/larastan` 3 + `phpstan-strict-rules` + `phpstan-deprecation-rules` | `level: max`, no baseline by the end of v2 |
| Tests | `pestphp/pest` 5 + `pest-plugin-laravel` | green suite on all 4 jobs |
| Architecture | Pest Arch (§6.2) | architectural rules as tests |
| Type coverage | `pest-plugin-type-coverage` | `--min=100` over `src/` |
| Line coverage | PCOV + Codecov | informational, no hard gate |
| Mutation | `pest-plugin-mutate` (§6.3) | `--covered-min` |
| Automated refactoring | `rector/rector` + `driftingly/rector-laravel` | `--dry-run` |
| Dependencies | `shipmonk/composer-dependency-analyser` + `ergebnis/composer-normalize` | unused deps and *shadow deps* |
| Compatibility | `roave/backward-compatibility-check` | public API break without a major |

Two items deserve highlighting because they solve concrete problems already identified:

- **`composer-dependency-analyser`** would have caught the unused `tecnickcom/tc-lib-pdf`
  (§1.11) automatically, and it also detects *shadow dependencies* — using classes that come
  from transitive dependencies, which break without warning when the intermediate updates.
- **Roave BC Check** turns the SemVer promise in `CONTRIBUTING.md` into a CI gate: it compares
  the PR's public API against the last tag and fails on a break without a major bump. Given
  that §4 bets on an extensive BC layer, this stops being optional.

### 6.2 Arch tests — the centrepiece

This is the item that pays off most in this particular refactor: it turns this document into
executable rules, preventing the architecture from eroding after merge.

```php
arch('value objects are immutable')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Data')
    ->toBeReadonly()->toBeFinal();

arch('contracts are interfaces')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Contracts')
    ->toBeInterfaces();

arch('nothing writes to disk outside Support')
    ->expect('Illuminate\Support\Facades\File')
    ->toOnlyBeUsedIn('LSNepomuceno\LaravelA1PdfSign\Support');

arch('no shell-out outside the CLI driver')
    ->expect(['Symfony\Component\Process', 'exec', 'shell_exec', 'proc_open'])
    ->toOnlyBeUsedIn('LSNepomuceno\LaravelA1PdfSign\Certificates\OpenSslCliCertificateReader');

arch('legacy stays contained')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Sign')
    ->toBeDeprecated();

arch()->preset()->php();      // no debug functions, no die/dd/var_dump
arch()->preset()->security(); // no eval, no md5/sha1, no insecure rand
```

The two middle rules justify the whole section on their own: they **guarantee** that problems
§1.1 and §1.2 cannot come back — no new class can write a private key to disk or open an
external process without going through the audited points.

### 6.3 Mutation testing — through Pest, no Infection

**Decided: `pest-plugin-mutate` instead of Infection.** With the PHP 8.4 floor, plugin v5
(`php: ^8.4`, `pest: ^5.0`) installs on every job. The gain is dropping an entire tool from
the stack: one runner, one config file, one report format, and no PHPUnit↔Infection adapter
to maintain.

```bash
pest --mutate --covered-min=85 --path=src/Certificates,src/Validation
```

For a digital-signature package this is genuinely valuable: tests that only assert "it did not
throw" keep passing with broken validation — and that is exactly where the risk lives.

- **Initial scope:** `src/Certificates/` and `src/Validation/`. Running mutation over the
  whole package would be slow — part of the suite generates real certificates and, on the CLI
  driver, spawns an `openssl` process per call. Widen it once stable.
- **Gate:** `--covered-min`, starting at the value measured on the first run and rising
  gradually. Never fix the target before having the measurement.
- **Prerequisite:** a coverage driver. CI currently runs `coverage: none`; adopt **PCOV**,
  much faster than Xdebug for this purpose.

The only area where Infection still leads is report maturity and incremental caching. That
does not justify a second tool in a package this size — if the scope ever grows to the whole
of `src/`, it is worth revisiting.

### 6.4 PHPUnit → Pest migration

> **Revised after PR 3 — drift was tried and discarded.** On this codebase
> `pestphp/pest-plugin-drift` corrupted `tests/TestCase.php`, leaving method bodies without
> their signatures, emitted `uses()` above the import block, and scaffolded `Feature/` and
> `Unit/` example directories the package does not want. With six test files totalling ~590
> lines, converting by hand was both safer and better. The plugin is not a dev dependency.
>
> The lesson generalises: a codemod is worth it at scale, not at this size.

`pestphp/pest-plugin-drift` converts `TestCase` classes to Pest syntax automatically. It is a
**one-shot codemod**, meant to be run locally and then removed — it does not need to become a
permanent `require-dev` entry.

Testbench's base `TestCase` still exists, referenced through `uses()` in `tests/Pest.php`.
The `src/Temp/` cleanup in `tearDown()` disappears along with `src/Temp/` itself (§3c).

### 6.5 CI structure

The current workflow is a single job triggered only on `pull_request`. Proposal:

| Job | Runs on | Contents |
|---|---|---|
| `tests` | 4-cell matrix | Pest + arch tests + PCOV, upload to Codecov |
| `quality` | PHP 8.5, single cell | Pint `--test`, PHPStan, Rector `--dry-run`, dependency-analyser, composer-normalize `--dry-run`, type-coverage |
| `mutation` | PHP 8.5, PRs touching `src/` | `pest --mutate --covered-min` |
| `bc-check` | PHP 8.5, PRs | Roave, against the last tag |

Additional adjustments: also trigger on `push` to the main branches; add `concurrency` with
`cancel-in-progress`; extend `dependabot.yml` to the `github-actions` ecosystem (today it
covers only `composer`).

### 6.6 Modern PHP features — where each one solves a real problem

The 8.4 floor unifies the toolchain (Pest 5, mutate, arch, type-coverage and Roave install on
**every** job, with no `||` constraints and no `bamarni/composer-bin-plugin`) and unlocks the
features below. The list is ordered by concrete value, not by novelty.

#### Attributes

| Attribute | PHP | Where | Why |
|---|---|---|---|
| `#[\SensitiveParameter]` | 8.2 | every `$password` parameter | **Redacts the password from stack traces and logs.** Today any exception thrown inside `fromPfx()` exposes the certificate password in the trace — this attacks §1.1 directly |
| `#[\Deprecated]` | 8.4 | BC-layer shims (§4) | Native: emits `E_USER_DEPRECATED` **and** is recognized by IDEs and PHPStan. Replaces a manual `trigger_error()` in every shim |
| `#[\Override]` | 8.3 | `Contracts` implementations | The compiler guarantees the signature still matches the contract — catches silent breakage during refactors |
| `#[\NoDiscard]` | 8.5 | `PendingSignature` fluent methods, `sign()` | Prevents the classic mistake of calling a fluent setter and discarding the return. **Inert on 8.4** (attributes are only resolved through Reflection), so it can be adopted right away |

`#[\SensitiveParameter]` is the highest-return item in the table: it is a security fix
disguised as modernization, and it costs one line per signature.

#### Type and structure features

- **Asymmetric visibility (8.4)** — `public private(set)` on the `Data/` VOs and on
  `PendingSignature` state: public reads without full `readonly`, writes internal only.
  Replaces half of the trivial getters.
- **Property hooks (8.4)** — derived properties on `Certificate` (e.g. `$expiresAt`,
  `$isExpired`) without turning them into methods or duplicating state.
- **Enums with interfaces + methods** — `SealPage`, `FontSize` and `ImageDriver` carrying
  their own behaviour (e.g. `FontSize::pixels()`), instead of the `match` scattered through
  `SealImage::breakText()` today.
- **Typed class constants (8.3)** and **whole-class `readonly` (8.2)** on the VOs.
- **`new` in initializers (8.1)** — already used in `SealImage::__construct()`; standardize.
- **`array_find` / `array_any` / `array_all` (8.4)** — replace the accumulator `foreach`
  loops in `ValidatePdfSignature::convertPlainTextToObject()`.

#### Deliberately excluded

- **Pipe operator `|>` (8.5)** and **`clone with` (8.5)** — would require an 8.5 floor, which
  would cut Laravel 12. Revisit in v3.
- **Lazy objects (8.4)** — no use case here; nothing in the package is expensive enough to
  justify lazy initialization.

The criterion is worth recording: a modern feature gets in when it **removes code or removes a
class of bug**. `#[\SensitiveParameter]` and `#[\Deprecated]` qualify. The pipe operator does
not.

### 6.7 Development environment — Docker

The proposed floor (PHP 8.4) sits above what is typically installed on the development
machine — this project's runs **8.1.34**, not even enough to load the current `vendor/`,
which resolved for `>= 8.2`. Rather than forcing several versions onto the host, `.docker/`
reproduces any cell of the CI matrix.

```
.docker/
├── Dockerfile      # PHP parameterized by ARG PHP_VERSION, alpine-cli
└── compose.yaml    # services php (8.4, default), php83 and php85
```

The image ships `openssl`, `gd`, `imagick` and **`pcov`** — the last one required by coverage
and mutation testing (§6.3), and absent from the official images.

```bash
docker compose -f .docker/compose.yaml run --rm php   composer install
docker compose -f .docker/compose.yaml run --rm php   vendor/bin/pest
docker compose -f .docker/compose.yaml run --rm php85 composer check
```

Each service mounts its own named volume at `/app/vendor` (`vendor-83`, `vendor-84`,
`vendor-85`), so switching versions does **not** invalidate the other install — which is what
makes reproducing the matrix locally practical without re-running `composer install` on every
switch.

> `.docker/` is for development and CI only: it must not ship in the distributed package.
> Enforce that in `.gitattributes` with `export-ignore` (alongside `tests/`, `poc/` and the
> like) in PR 1.

### 6.8 Development shortcuts

Scripts in `composer.json` to reproduce CI locally with a single command:

```json
{
    "scripts": {
        "test": "pest",
        "test:cov": "pest --coverage --min=90",
        "test:types": "pest --type-coverage --min=100",
        "test:arch": "pest --group=arch",
        "test:mutate": "pest --mutate --covered-min=85",
        "lint": "pint",
        "analyse": "phpstan analyse",
        "refactor": "rector",
        "check": ["@lint --test", "@analyse", "@test", "@test:types"]
    }
}
```

Git hooks (CaptainHook/GrumPHP) are **out** of scope: a well-defined `composer check`
delivers the same value without imposing tooling on contributors.

---

## 7. Decisions

### Settled

| # | Question | Decision |
|---|---|---|
| 1 | Laravel floor | **Laravel 12** — the only version besides 13 that reaches PHP 8.5, and the oldest still under security support (§3e.1) |
| 2 | Mutation: Infection or Pest? | **`pest-plugin-mutate`** — one tool fewer, one runner, one report (§6.3) |
| 3 | Attributes and modern features | **Adopt**, under the criterion "removes code or removes a class of bug" (§6.6). `#[\SensitiveParameter]` enters as a security fix |
| 4 | PDF engine | **Migrate to `tc-lib-pdf`** — TCPDF 6 is officially deprecated; the migration unlocks LTV and TSA (§3g). Legacy driver kept as optional |
| 5 | Multi-signature | **Our own incremental writer**, clean-room from ISO 32000-1 — no dependency delivers this (§3h) |
| 6 | Use `ddn/sapp`? | **No, under no circumstances** — not `require`, not `require-dev`, not `suggest`. LGPL is incompatible with porting code into an MIT package, and it is a legacy project. **Conceptual** reference only; clean-room implementation over tc-lib-pdf's building blocks, with an arch test enforcing the rule (§3h) |
| 7 | PHP floor: 8.4 or 8.3? | ✅ **8.4**, applied in PR 1. Keeps the toolchain on one Pest major and unlocks property hooks, `private(set)` and `#[\Deprecated]` |
| 8 | Multi-signature in v2.0 or v2.1? | ✅ **v2.0.** PR 0b closed with 3/3 valid signatures (§3h.1) — the risk that would justify deferring did not materialize |

### Open

| # | Question | Recommendation |
|---|---|---|
| 9 | `IncrementalSigner` as the default, or only for the 2nd signature? | **Default** — preserving the original bytes from the 1st signature onward removes the silent destruction of annotations and form fields (§3h) |
| 10 | Keep the legacy driver? | **Yes, as optional** — guarantees byte-for-byte fidelity for anyone depending on v1 output, without carrying deprecated deps in the default install |
| 11 | phpseclib now or later? | **Later (v2.1)** — v2.0 is already a large refactor. Revisit: tc-lib-pdf may already cover part of the validation |
| 12 | Full BC layer or a clean v2? | ✅ **Clean break**, decided during PR 5. A 3.0 is far enough out that a shim kept "until then" is kept indefinitely, and each one constrains the design it wraps. The PHP 8.4 / Laravel 12 floor already forces a deliberate upgrade, so the marginal cost of also renaming call sites is small — and `UPGRADE.md` carries the mapping (§4) |
| 13 | PHPStan `level: max` from the start, or a baseline? | ✅ **Both, applied in PR 2.** `level: max` with a 216-entry baseline. Measured: 95 errors at level 5, 159 at level 8, 216 at max — so max costs only 57 extra baseline entries over level 8 and gates all new code at the strictest setting |
| 14 | Line-coverage gate? | **No** — type coverage (100%) and mutation are more honest gates; line coverage stays informational |

> **History:** the original plan proposed a PHP 8.2 / Laravel 10 floor. It was invalidated
> twice — first by the real tooling requirements, then by the realization that the PHP
> *ceiling* of older Laravel versions, not the floor, is the limiting factor (§3e.1).
