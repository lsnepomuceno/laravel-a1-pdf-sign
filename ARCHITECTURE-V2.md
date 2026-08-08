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

## 3. Technical decisions — moved

One file per decision under `docs/decisions/`, numbered, with the number as
the identifier code cites. See `docs/decisions/README.md`.

The engine migration (TCPDF → tc-lib-pdf → tc-lib-pdf-sign) and the reverted
dependency removal were history, not decisions still in force, and moved to
`docs/history/v2-modernization.md`.

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
