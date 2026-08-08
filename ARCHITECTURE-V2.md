# Modernization plan — v2.0

Reference document for the architectural refactor of the package. Baseline: `1.0.9`.
Status: **delivered** — every PR in the roadmap (§5) has landed. Two gaps remain open, recorded at the end of §5.

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

    ✅ **Fixed in PR 6.** `CertificateVault::open()` parses the stored PEM directly, which
    also removes a temporary file and a process spawn. The round-trip test passes.

---

## 2. Architecture — moved

The target layout and public API now live in `docs/spec/public-api.md`,
written from the code rather than from this plan. What was planned here and
not built is recorded in `docs/history/v2-modernization.md`.

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

**Revised in PR 12 — the remaining shell-out runs through Laravel, not Symfony.**
`Support\ProcessRunner` was built on `Symfony\Component\Process` directly; it now takes
`Illuminate\Process\Factory`. This does **not** remove Symfony from the tree —
`illuminate/process` requires `symfony/process` — so the honest framing is not "one less
dependency" but two concrete gains: the direct require becomes an Illuminate one, matching
every other dependency the package declares, and a host application can `Process::fake()` the
call in its own suite, which is impossible against a class instantiated inline. The arch rule
that confines shell-out was widened to cover `Illuminate\Process` as well, so the audit
boundary did not move.

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

> **Revised during PR 12 — floor raised to Laravel 13.** The analysis above weighs the
> framework alone; it misses the test stack. **Pest 5 requires `symfony/process ^8.1` and
> Laravel 12 requires `^7.2`**, so the two cannot be installed in the same tree: the Laravel 12
> cell of the matrix fails at `composer update`, before a single test runs. Supporting it means
> either keeping Pest 4 for that cell — the split stack §3e.2 rejects below — or shipping
> support that CI never exercises. Laravel 12 leaves security support in 2027, so the cost of
> carrying it outweighs the remaining year of life. **Final floor: Laravel 13, PHP 8.4.**

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

| | Laravel 13 |
|---|:---:|
| **PHP 8.4** | ✓ |
| **PHP 8.5** | ✓ |

**2 jobs against the current 11**, and every cell under active support from both vendors.
(The table originally carried a Laravel 12 column; it was dropped for the reason recorded in
§3e.1.)
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

> **Moot as of PR 7c.** tc-lib-pdf left the dependency list along with the rendering it was
> brought in for: the package appends revisions to bytes it already has and never emits a
> document, so no font definition is ever loaded. Nothing under `resources/fonts/` shipped,
> and `K_PATH_FONTS` must stay **undefined** — tc-lib-pdf and TCPDF 6 read it in different
> formats, and defining it kills TCPDF silently. The analysis below is kept for the record.

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
> **Resolved in PR 7 — the inherited path cannot be reused.** `appendIncrementalRevision()`
> is `protected` and its docblock accepts "the complete PDF", but the two methods it delegates
> to are `private`, and `buildIncrementalTrailer()` writes `/Root $this->objid['catalog']`,
> `/Info $this->objid['info']` and its own `/ID` — the identifiers of a document *tc-lib-pdf
> itself built*.
>
> Probed against `tests/Resources/test.pdf`, whose catalog is object 14:
>
> ```
> trailer << /Size 100 /Root 0 0 R /Info 0 0 R /Prev 8605 /ID [ <b004…> <b004…> ] >>
> ```
>
> `/Root 0 0 R` points at an object that does not exist in that document. The output is a
> broken PDF, and because the trailer builder is private a subclass cannot correct it.
>
> The machinery therefore serves only post-signing DSS (B-LT) and DocTimeStamp (B-LTA)
> revisions on tc-lib-pdf's own output. **PR 7b ships the PoC 0b writer**, as originally
> designed.

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

### i) PEM certificates — a second entry point, one pipeline

The package accepts PKCS#12 (`.pfx` / `.p12`) and nothing else. Every public entry — the
builder's `certificate()`, `certificateFromUpload()`, `signFromFile()`, `signFromUpload()`,
`encryptCertificate()` and the `pdf:sign` command — funnels into `CertificateReader::read()`,
whose two implementations call `openssl_pkcs12_read()` or `openssl pkcs12`. The contract's
own docblock says "the raw bytes of a .pfx / .p12 file".

#### The pipeline is already PEM

That closed door hides how little has to change. **PKCS#12 is not a peer of PEM here — it is
a container that gets converted into PEM**, by `NativeCertificateReader::toPem()`, before
anything else runs. PEM is the destination format, not a sibling:

| Component | What it already handles |
|---|---|
| `Data\Certificate::$original` | The combined certificate and private key, in PEM |
| `CertificateParser::parse()` | Takes a PEM bundle and a password; the single "is this usable" answer |
| `CertificateVault::open()` | Reparses the stored PEM directly — no PKCS#12 round-trip (§1.14) |
| `Cades\CadesBuilder` | Extracts the chain with a PEM regex, and reads the key from `$original` |

A caller can already reach this by hand — `app(CertificateParser::class)->parse($pem, $pw)`
into `usingCertificate()` — which is an undocumented back door, and a broken one for the
common case below.

#### The defect this uncovered

`CertificateParser` passes the bundle to `openssl_x509_check_private_key()` as a **string**.
That form cannot decrypt a passphrase-protected private key, which is what a real `.pem`
usually carries. Measured against ext-openssl:

| Call | Result |
|---|---|
| `check_private_key($x509, $pem)` — encrypted key | **FAIL** — what the code does today |
| `check_private_key($x509, [$pem, $password])` — encrypted key | OK |
| `check_private_key($x509, [$pem, 'wrong'])` | FAIL — a wrong passphrase still fails, as it must |
| `check_private_key($x509, [$pem, $password])` — **unencrypted** key | OK |
| `x509_read()` on a bundle with the key written before the certificate | OK — order is irrelevant |
| `x509_read()` on DER bytes | FAIL — detectable, so it can be reported as a format error |
| `pkey_get_private($plainPem, 'anything')` | OK — a passphrase on an unencrypted key is ignored |

The array form is correct for encrypted *and* unencrypted keys, so the fix is uniform and
needs no branch. It is a prerequisite: without it any PEM carrying a protected key fails with
`InvalidX509PrivateKeyException`, which points at the wrong cause.

Note the asymmetry — `CadesBuilder::privateKey()` already passes the password correctly. Only
the parser does not, which is why PKCS#12 never exposed the bug: `openssl_pkcs12_read()`
hands back a *decrypted* key, so the string form was always enough.

#### The decision: diverge at the entry, converge at the reader

A parallel PEM hierarchy — its own contract, its own DTO, its own pipeline — was considered
and rejected. `CertificateParser` already states the principle it would contradict: *"Both
readers converge here, so 'is this certificate usable' is answered in one place rather than
once per driver."* Two contracts that both take `(bytes, password)` and both return
`Certificate` are one contract written twice.

The blast radius of forking the DTO is what settles it:

| Would have to fork | Because |
|---|---|
| `Cades\CadesBuilder` | `certificates()` and `privateKey()` both read `Certificate::$original` |
| `Certificates\CertificateVault` | `seal()` and `open()` are typed on `Certificate` |
| `Seal\InterventionSealRenderer` | `render()` is typed on `Certificate` |
| `Signing\PendingSignature` | Would carry both readers, on top of its three dependencies |
| `tests/ArchTest.php` | The `Data` rules — final, readonly, extends `BaseData` — would govern a second DTO |
| The public API | `Data\*` are public return types; the shape would change in two places, permanently |

Where the separation is real is **at the entry point**, and only there:

1. **Arity.** PEM may arrive as two files, a certificate and a key. PKCS#12 is always one.
   That is a genuinely different signature, so it gets its own method rather than an
   overloaded one.
2. **Diagnostics.** "wrong password or unsupported encryption" does not describe "these are
   DER bytes, not PEM" or "the certificate does not match the key".
3. **Security posture.** A PKCS#12 bundle is always encrypted. A PEM private key frequently
   is not, and the documentation has to say so.

```
certificate()      certificateFromUpload()      certificatePem()
      │                     │                          │
      └──────── CertificateReader::read() ─────────────┘
         Native / OpenSslCli          Pem (no conversion step)
                        │                     │
                        └──► CertificateParser::parse($pem, $password)
                                        │
                                   Data\Certificate
                                        │
                     CadesBuilder · CertificateVault · SealRenderer
```

`PemCertificateReader` implements the existing `CertificateReader`, so it stays swappable and
mockable, and it is the degenerate case of that contract: the reader whose conversion step is
empty. It does **not** belong to `ReaderFactory`, whose axis is *how to read PKCS#12* —
native, or the CLI for legacy RC2/40-bit bundles (§3a). That axis does not apply to a format
that needs no conversion, and the reader's only dependency is `CertificateParser`, so it
autowires.

Format is detected **by content**, not by extension. PEM ships as `.pem`, `.crt`, `.cer`,
`.key` and `.txt`; gating on extension would reproduce the rigidity of the existing
`InvalidPFXException` check, which rejects a valid bundle for having the wrong suffix.

#### Scope

| File | Change |
|---|---|
| `Certificates/CertificateParser.php` | The array form of `check_private_key`. **Prerequisite** |
| `Certificates/PemCertificateReader.php` | New. Combined bundle or separate cert + key; format diagnostics |
| `Exceptions/InvalidPemContentException.php` | New. Only what is decidable *before* parsing: no certificate block, no private key block, binary DER/PKCS#12 bytes handed to the PEM entry. A certificate and key that are both present but unrelated keeps `InvalidX509PrivateKeyException`, whose message already says exactly that — a second class for it would be a synonym |
| `Contracts/CertificateReader.php` | `$pfxContents` → `$contents`, and a format-agnostic docblock. Verified safe: every call site is positional, none uses named arguments |
| `Signing/PendingSignature.php` | `certificatePem()` and `certificateFromPem()` |
| `Contracts/A1PdfSign.php` + `A1PdfSignManager.php` | `signFromPem()`; `encryptCertificate()` accepting PEM |
| `Commands/SignPdfCommand.php` | Content sniffing, plus `--key=` for the two-file form |
| `Testing/DebugCertificate.php` | `makePem()`, in both the encrypted-key and plain-key variants |

Adding a method to `Contracts\A1PdfSign` breaks external implementers. With 2.0.0 unreleased
this is the moment it costs nothing; afterwards it costs a major.

No new configuration key. Nothing about PEM is an infrastructure decision the host
application would want to set once — the format is a property of the file in hand.

#### Tests

Beyond the per-class cases, three carry the design:

1. **Convergence.** Read the same key material through both paths — PKCS#12, and the PEM
   extracted from it — and assert the two `Certificate` objects agree. This is the executable
   form of "one pipeline"; if it ever fails, the hierarchy has forked in practice.
2. **End to end.** Sign through `certificatePem()` and validate with
   `Validation\PdfSignatureValidator`, then confirm the result in poppler's `pdfsig`. The
   suite shares its assumptions with the code it tests (§5, *Still open*), so the external
   reader is the one that counts.
3. **The encrypted key.** Sign with a passphrase-protected PEM, with the correct passphrase
   and with a wrong one. This is the case that fails today and the reason the parser fix is a
   prerequisite rather than a cleanup.

Fixtures go through `Testing\DebugCertificate::makePem()`, and the shared helper belongs in
`tests/Pest.php` — a helper defined inside a test file is invisible to the others under
`--parallel`.

`src/Certificates` is under the mutation gate at a floor of 58. The floor is a measurement,
not a target: re-measure after the code lands and only then decide whether to move it (§6.3).

#### Samples

No new sample PDF. The format changes only how the key is loaded; the signature it produces
is indistinguishable from the PKCS#12 one, so a `pem-signed.pdf` next to the profile samples
would imply a distinction that does not exist. What is worth shipping is the **input**:
`samples/certificate.pem` alongside `certificate.pfx`, so the new entry point can be
exercised against the same throwaway certificate the rest of `samples/` uses.

`poc/sign-samples.php` gains the PEM export and one signing round through `certificatePem()`,
asserted to validate — the generator is where the two paths are shown to converge on real
output rather than in a unit test.

**`.gitignore` must be extended first.** It ignores `*.pfx` and negates `/samples/*.pfx`, but
`*.pem` and `*.key` are not ignored at all. Shipping a PEM sample without that rule leaves a
repository where a contributor's real private key is one `git add` away from being committed —
and unlike a `.pfx`, a PEM key is often unencrypted.

#### Concrete risks

| Risk | Mitigation |
|---|---|
| An unencrypted private key on disk, which PKCS#12 never permitted | Cannot be prevented by the package; document it plainly and keep `#[\SensitiveParameter]` on every password argument |
| A PEM whose certificate and key do not match | Already caught by `check_private_key`, once the array form lands — surface it as its own message, not as "invalid content" |
| DER or PKCS#12 bytes passed to the PEM entry | Detect and name the actual format; `openssl_x509_read()` fails silently on DER |
| A chain-only PEM, with no private key | Fail before parsing, with a message that says which half is missing |
| The two-file form given the same path twice | Concatenating a certificate with itself passes `x509_read` and fails `check_private_key` with a misleading message; check for it explicitly |
| Divergence between the PEM and PKCS#12 paths over time | The convergence test above, which fails the moment the two stop agreeing |

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
v2 demands PHP 8.4 and Laravel 13, so nobody upgrades without touching their project — and
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
| 5b | ✅ Remove deprecated API, part 1 | **done** — `Entities\*` and the legacy constants dropped, `Data\*` final, idiomatic enum backing values, arch rule guarding the removal, `UPGRADE.md` (§4) | — |
| 6 | ✅ Certificates | **done** — `NativeCertificateReader` default, CLI fallback for legacy only, `CertificateVault` (fixes §1.14), `TemporaryFile`, `DebugCertificate` in `Testing/`, global helpers removed. 63 tests, no skips | — |
| 7 | ✅ Signing | **done** — `IncrementalSigner` bound to `PdfSigner`, `PendingSignature` + `SignedPdf`, FPDI and TCPDF dropped, disk round-trip gone. **No `TcLibPdfSigner`/`TcpdfSigner` pair**: 7c swapped tc-lib-pdf for tc-lib-pdf-sign, so the package no longer renders PDFs and the core fonts of §3g.2 became moot — `K_PATH_FONTS` is deliberately left undefined | — |
| 7b | ✅ Multi-signature | **done** — `Incremental/*` from PoC 0b, the fallback path: with tc-lib-pdf gone there was no `appendIncrementalRevision()` left to inherit. Closes TCPDF#430; `samples/` carries a six-signature document. `approval()` / `certify()` / `ltv()` were **not** built — the level is chosen by `profile()`, and `timestamp()` survives as its shorthand | — |
| 7c | ✅ PAdES profiles | **done** — CAdES builder replaces openssl_pkcs7_sign, B-B/B-T live, tc-lib-pdf swapped for tc-lib-pdf-sign (13 deps → 1) | — |
| 7d | ✅ DSS | **done** — B-LT, store in its own revision | — |
| 7e | ✅ DocTimeStamp | **done** — B-LTA, archive timestamp over the whole file | — |
| 8 | ✅ Seal | **done** — `InterventionSealRenderer` on Intervention Image v3 rather than the tc-lib-pdf API, which left with 7c. In-memory seal, driver/font/colour/background from config, `SealPlacement` + `Incremental\SealAppearance` stamping the widget | — |
| 9 | ✅ Validation | **done** — signatures verified cryptographically, all of them reported, text parsing gone | — |
| 10 | ✅ Mutation | **done** — 71.7% covered-MSI, gate at 70; found the ASN.1 boundary gaps | — |
| 11 | ✅ Cleanup + tooling | **done** — `ManageCert` retired, dependency-analyser and normalize wired in, README. Roave BC check deferred: it compares against a released tag, so it only becomes meaningful from 2.0.0 | — |
| 12 | ✅ Hardening | **done** — Laravel floor raised to 13, once Pest 5 and Laravel 12 proved uninstallable together (§3e.1); `ProcessRunner` rebuilt on `Illuminate\Process\Factory`, with the shell-out arch rule widened to match (§3a); mutation widened to `src/Signing/` and the `--covered-min` → `--min` flag corrected (§6.3); Husky pre-commit hook adopted for Pint (§6.9) | — |
| 13 | ✅ PEM certificates | **done** — `certificatePem()` / `certificateFromPem()` / `signFromPem()` onto the existing pipeline, `PemCertificateReader`, and the `check_private_key` fix they depended on. `pdf:sign` routes by content and takes `--key`; the vault detects the encoding; `.gitignore` covers `*.pem` / `*.key` and `samples/certificate.pem` is the PFX's own identity re-encoded. Verified in poppler (§3i) | — |

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

### Still open

Two things the plan set out to do are not done, and neither is covered by a passing test:

1. ✅ **Independently validated with poppler's `pdfsig`** (25.12), which reads the documents
   with its own PDF and CMS implementation rather than ours. Every profile reports
   *Signature is Valid*, the sub-filters are recognised, and a three-signature document shows
   all three valid with correctly progressive coverage.

   It found a real defect no test had caught. `ByteRangeCalculator::apply()` searched for the
   literal `/Contents <`, but tc-lib-pdf-sign writes `/Contents<` without the space, so the
   archive timestamp revision located the *signature's* placeholder and overwrote it. poppler
   reported the signer as `www.freetsa.org` and the digest as mismatched. The lookup is now a
   regex tolerant of both spellings.

   A second defect surfaced from the same run: our own validator treated the archive timestamp
   as a signature over the document and reported a valid B-LTA as invalid. A `/DocTimeStamp`
   signs the TSTInfo holding the document's hash, not the document, so it is now told apart
   and reported separately.

   The archive timestamp was verified end to end: its imprint equals the SHA-256 of the bytes
   its own `/ByteRange` covers.

   **Still not done:** Adobe Reader and the ITI Validar. Both need a real ICP-Brasil
   certificate to say anything meaningful about trust — poppler already reports
   *Certificate issuer isn't Trusted* for the self-signed test certificate, which is correct
   and expected. `pdfsig` cannot verify `ETSI.RFC3161` document timestamps either, so that
   part rests on the OpenSSL check above.
2. **Roave BC check is not wired in.** It compares the public API against a released tag;
   with 2.0.0 unreleased and everything breaking against `^1`, it would only produce noise.
   It becomes meaningful — and should be added — once 2.0.0 ships.

---

## 6. Quality toolchain — moved

The gates and their rationale now live in `docs/spec/quality-policy.md`.
The tooling that was planned and never adopted is recorded in
`docs/history/v2-modernization.md`.

---

## 7. Decisions

### Settled

| # | Question | Decision |
|---|---|---|
| 1 | Laravel floor | **Laravel 13** — revised in PR 12. Laravel 12 reaches PHP 8.5 and is still supported, but it pins `symfony/process ^7.2` while Pest 5 needs `^8.1`, so the cell cannot even be installed (§3e.1) |
| 2 | Mutation: Infection or Pest? | **`pest-plugin-mutate`** — one tool fewer, one runner, one report (§6.3) |
| 3 | Attributes and modern features | **Adopt**, under the criterion "removes code or removes a class of bug" (§6.6). `#[\SensitiveParameter]` enters as a security fix |
| 4 | PDF engine | **Migrate to `tc-lib-pdf`** — TCPDF 6 is officially deprecated; the migration unlocks LTV and TSA (§3g). Legacy driver kept as optional |
| 5 | Multi-signature | **Our own incremental writer**, clean-room from ISO 32000-1 — no dependency delivers this (§3h) |
| 6 | Use `ddn/sapp`? | **No, under no circumstances** — not `require`, not `require-dev`, not `suggest`. LGPL is incompatible with porting code into an MIT package, and it is a legacy project. **Conceptual** reference only; clean-room implementation over tc-lib-pdf's building blocks, with an arch test enforcing the rule (§3h) |
| 7 | PHP floor: 8.4 or 8.3? | ✅ **8.4**, applied in PR 1. Keeps the toolchain on one Pest major and unlocks property hooks, `private(set)` and `#[\Deprecated]` |
| 8 | Multi-signature in v2.0 or v2.1? | ✅ **v2.0.** PR 0b closed with 3/3 valid signatures (§3h.1) — the risk that would justify deferring did not materialize |
| 15 | PEM: parallel pipeline, or a second entry onto the existing one? | ✅ **Second entry, one pipeline.** A separate contract and DTO would fork `CadesBuilder`, `CertificateVault`, `SealRenderer` and the public `Data\*` shape to gain nothing: PKCS#12 is *converted into* PEM before anything downstream runs, so the two are not peers. Divergence is confined to the entry point, where it is real — PEM may be two files, and its key is often unencrypted (§3i) |

### Open

| # | Question | Recommendation |
|---|---|---|
| 9 | `IncrementalSigner` as the default, or only for the 2nd signature? | **Default** — preserving the original bytes from the 1st signature onward removes the silent destruction of annotations and form fields (§3h) |
| 10 | Keep the legacy driver? | **Yes, as optional** — guarantees byte-for-byte fidelity for anyone depending on v1 output, without carrying deprecated deps in the default install |
| 11 | phpseclib now or later? | **Later (v2.1)** — v2.0 is already a large refactor. Revisit: tc-lib-pdf may already cover part of the validation |
| 12 | Full BC layer or a clean v2? | ✅ **Clean break**, decided during PR 5. A 3.0 is far enough out that a shim kept "until then" is kept indefinitely, and each one constrains the design it wraps. The PHP 8.4 / Laravel 13 floor already forces a deliberate upgrade, so the marginal cost of also renaming call sites is small — and `UPGRADE.md` carries the mapping (§4) |
| 13 | PHPStan `level: max` from the start, or a baseline? | ✅ **Both, applied in PR 2.** `level: max` with a 216-entry baseline. Measured: 95 errors at level 5, 159 at level 8, 216 at max — so max costs only 57 extra baseline entries over level 8 and gates all new code at the strictest setting |
| 14 | Line-coverage gate? | **No** — type coverage (100%) and mutation are more honest gates; line coverage stays informational |

> **History:** the original plan proposed a PHP 8.2 / Laravel 10 floor. It was invalidated
> twice — first by the real tooling requirements, then by the realization that the PHP
> *ceiling* of older Laravel versions, not the floor, is the limiting factor (§3e.1).
