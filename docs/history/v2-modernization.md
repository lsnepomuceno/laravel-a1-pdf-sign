# The v2 modernisation, as planned

A record, not a specification. Nothing here is authoritative about how the
package behaves today. For that, read [the public API](../spec/public-api.md),
[the invariants](../spec/invariants.md) and [the quality policy](../spec/quality-policy.md).

It is kept because it answers questions the current code cannot: why v1 was
shaped the way it was, which alternatives were weighed, and where the finished
work diverged from the plan that produced it.

The full original text of the plan is preserved at tag `2.0.0`, as
`ARCHITECTURE-V2.md`.

---

## The planned architecture, and what was built instead

The plan's §2 sketched a target layout before any of it existed. Four parts of
that sketch were never built, and the sketch was never reconciled, which is the
drift this reorganisation exists to stop.

| Planned | Built | Why it diverged |
|---|---|---|
| `Signing/TcLibPdfSigner.php` + `Signing/TcpdfSigner.php` | `Signing/IncrementalSigner.php` | PR 7c swapped tc-lib-pdf for tc-lib-pdf-sign, so the package stopped rendering PDFs entirely. With no renderer there was no driver pair to choose between. |
| `Enums/SealPage.php` | `Data\SealPlacement::LAST_PAGE` | The page is one field of a placement, not a concept with its own behaviour. |
| `->approval()` / `->certify()` / `->ltv()` | `->profile()` | The PAdES level determines all three. Choosing the level once is the same decision, expressed as one call instead of three that can contradict each other. |
| `Console/` | `Commands/` | Laravel's own convention. |
| `'temp_disk'`, `'signer' => 'tc-lib-pdf'` | `'temp_path'`, no `signer` key | The disk abstraction bought nothing once temporary files stopped living in `vendor/`; the signer key described a driver choice that no longer exists. |

The planned entry point was `A1PdfSign::certificate($pfx, $pass)->pdf(…)`. What
shipped puts a `newSignature()` in front of it, so the facade can carry one-shot
methods (`signFromFile`, `signFromPem`, `signFromUpload`) alongside the builder
without overloading a single name.

## The PDF engine: TCPDF → tc-lib-pdf → neither

The trigger was not preference. **`tecnickcom/tcpdf` 6 was discontinued by its
author on 2026-05-30**, and `tc-lib-pdf` is its official successor. Staying put
meant building v2 on an engine with no upstream.

An earlier step in the plan proposed removing `tecnickcom/tc-lib-pdf` on the
grounds that it was declared in `composer.json` and used nowhere. The
observation was right and the conclusion was wrong: it was unused because it had
not been adopted *yet*. That removal was reverted, and what left instead was
`tecnickcom/tcpdf` and, with it, `setasign/fpdi`.

Then the destination moved as well. PR 7c swapped `tc-lib-pdf` for
`tc-lib-pdf-sign`, thirteen transitive dependencies down to one, because the
package had stopped needing a PDF *engine* at all. Once signing became
[appending a revision](../decisions/0006-incremental-revision.md), nothing
renders a document: the bytes already exist and only get extended.

The proof-of-concept that preceded all of this answered the one question that
could have invalidated the plan: whether tc-lib-pdf delivered LTV and
timestamping in practice rather than in a docblock. It did: 15/15 checks, with a
live TSA round-trip. A second spike proved the incremental writer on its own,
3/3 signatures valid. Both live in `poc/`.

## Fonts, a blocker that evaporated

tc-lib-pdf could not emit any PDF without a generated font definition, not even
a signature-only document containing no text:

```
Com\Tecnick\Pdf\Font\Exception: unable to read file: helvetica.json
```

TCPDF 6 bundles 165 fonts in PHP format; tc-lib-pdf-font expects JSON. Not
interchangeable. The plan therefore added unforeseen scope: generate the core-14
metrics, ship them under `resources/fonts/`, and define `K_PATH_FONTS` from the
service provider.

**None of it was needed.** PR 7c removed tc-lib-pdf along with the rendering it
had been brought in for. The package appends revisions to bytes it already has
and never emits a document, so no font definition is ever loaded.

What survives is the inverse rule, and it is load-bearing: `K_PATH_FONTS` must
stay **undefined**, because tc-lib-pdf and TCPDF 6 read it in different formats
and defining it kills TCPDF silently. That now lives in
[the invariants](../spec/invariants.md).

## Quality tooling that was planned and not adopted

The plan's §6 listed a stack assembled before any of it was installed. Most of
it shipped; these did not.

- **Roave `backward-compatibility-check`.** Deferred in PR 11: it compares
  against a released tag, so it only became meaningful from 2.0.0 onward. Still
  worth having.
- **`rector/rector` + `driftingly/rector-laravel`.** Planned as a `--dry-run`
  gate; never wired in.
- **A `bc-check` CI job and a `test:arch` script.** Neither exists; arch tests
  run as part of the ordinary suite.
- **The arch rules as first drafted.** `legacy stays contained` expected
  `LSNepomuceno\LaravelA1PdfSign\Sign` to be deprecated, but that namespace was
  deleted outright rather than deprecated, so the rule became
  `no deprecated namespace lingers`. `no shell-out outside the CLI driver`
  pinned the exception to `OpenSslCliCertificateReader`; PR 12 rebuilt
  `ProcessRunner` on `Illuminate\Process\Factory` and widened the rule to that
  single helper instead.

Several PHP-8.4 features were listed as targets and deliberately not taken:
asymmetric visibility on the value objects, property hooks on `Certificate`, and
`#[\NoDiscard]` on the fluent methods. The criterion the plan set for itself,
that a feature earns adoption when it removes code or removes a class of bug, ruled
them out on its own terms.

## The PHPUnit → Pest migration, and a codemod that failed

`pestphp/pest-plugin-drift` was meant to convert the `TestCase` classes
automatically. On this codebase it corrupted `tests/TestCase.php`, leaving
method bodies without their signatures, emitted `uses()` above the import block,
and scaffolded `Feature/` and `Unit/` directories the package does not want.

With six test files totalling ~590 lines, converting by hand was both safer and
faster. The plugin was never taken as a dev dependency.

The lesson generalises, and it is why no codemod has been adopted since: a
codemod is worth it at scale, not at this size.

## Where v1 stood

Real problems imposed by the current architecture, not matters of taste.

### Security / robustness

1. `ManageCert::fromPfx()` writes the **PEM containing the private key in plain text** into
   `src/Temp/`, that is, inside the consuming application's `vendor/`, and the certificate
   password travels on the command line (`-password pass:...`), visible through `ps` to any
   user on the machine.
2. Temp files are not removed on exception paths. `File::delete()` always comes after code
   that can throw; the test `tearDown()` only masks the leak.
3. `a1TempDir()` requires the package directory to be writable, with a silent fallback to
   `sys_get_temp_dir()`: non-deterministic behaviour across environments.

### Coupling / testability

4. The public API is six global functions in `autoload.files`. No namespace, not mockable,
   no class autocompletion in the IDE, and any signature change is breaking.
5. Zero contracts, zero container bindings, zero facade. The `ServiceProvider` only registers
   two commands. Nothing is swappable by the consumer.
6. `ManageCert` carries three responsibilities: PKCS#12 conversion, x509 parsing/validation
   and blob encryption. `makeDebugCertificate()`, which is test code, lives in a production class.

### Design

7. String constants (`MODE_RESOURCE`, `FONT_SIZE_LARGE`, `IMAGE_DRIVER_GD`) where PHP 8.1+
   offers enums.
8. `SignaturePdf` mixes *producing* and *delivering*: `signature()` calls `File::put()` and
   immediately `File::get()` back in `MODE_RESOURCE`, a pointless disk round-trip, since
   `TCPDF::output(..., 'S')` already returns the complete string.
9. `ValidatePdfSignature` extracts the PKCS#7 blob with a `ByteRange` regex and then does
   *text parsing* of `openssl pkcs7 -print_certs` output through three chained
   `preg_replace` calls. Fragile and coupled to the binary's output format.
10. No publishable config: nothing is configurable: temp disk, image driver, default seal,
    legacy flag.

### Hygiene

11. `tecnickcom/tc-lib-pdf: ^8` sits in `composer.json` and is **used nowhere** in `src/` or
    `tests/`. ⚠️ **Do not remove**. See the engine migration below: this "dead" dependency is precisely the
    official successor to TCPDF, and becomes the v2 engine.
12. **The PDF engine is officially deprecated.** `tecnickcom/tcpdf` 6 was discontinued by its
    author on 2026-05-30. See the engine migration below, the highest-impact change in this plan.
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
    spawn. It carries a compatibility question that needs an explicit decision: the
    `$isBase64` flag suggests some callers may be storing the raw PFX binary rather than the
    PEM, and those callers are served correctly by the current code path.

    ✅ **Fixed in PR 6.** `CertificateVault::open()` parses the stored PEM directly, which
    also removes a temporary file and a process spawn. The round-trip test passes.

---

## The roadmap, as executed

Independent PRs on the `v2.x-dev` branch.

| # | PR | Scope | Risk |
|---|---|---|---|
| 0 | ✅ **tc-lib-pdf PoC** | **done**: 15/15 checks, live TSA round-trip. See `poc/tc-lib-pdf-ltv-tsa/` and the engine migration below | n/a |
| 0b | ✅ **Incremental update PoC** | **done**: 3/3 signatures valid. See `poc/incremental-signature/` and [0006](../decisions/0006-incremental-revision.md) | n/a |
| 1 | ✅ PHP/Laravel floor | **done**: `">=8.4 <8.6"` / L12+, 4-job matrix, tc-lib-pdf `^8.67`, `.gitattributes`, PHP 8.4 nullable fixes. Suite green on 8.4 and 8.5 (21 passed) | n/a |
| 2 | ✅ Formatting + static analysis | **done**: Pint (PER-CS), PHPStan `level: max` + Larastan + strict/deprecation rules, **216-error baseline**, `quality` job, `composer check`, `CONTRIBUTING.md` | n/a |
| 3 | ✅ PHPUnit → Pest | **done**: Pest 5, `tests/Pest.php`, named datasets, **arch tests**, type coverage 94.3% gated in CI. `drift` tried and discarded (the codemod that failed, below) | n/a |
| 4 | ✅ Data + Enums | **done**: `Data/` readonly VOs, `Enums/` carrying behaviour, `Entities\*` as deprecated subclasses, `#[\SensitiveParameter]` on passwords, type coverage 95.7%. `SignatureInfo`, `SealPlacement` and `SignedPdf` deferred to PR 7, where they gain consumers | n/a |
| 5 | ✅ Package infrastructure | **done**: publishable config, `Contracts\A1PdfSign` bound as a singleton, facade, helpers delegating to the container, 4 more arch rules, **type coverage 100%**. Found defect finding 14 above. The finer-grained contracts land with their implementations in PRs 6-9 | n/a |
| 5b | ✅ Remove deprecated API, part 1 | **done**: `Entities\*` and the legacy constants dropped, `Data\*` final, idiomatic enum backing values, arch rule guarding the removal, `UPGRADE.md` ([UPGRADE.md](../../UPGRADE.md)) | n/a |
| 6 | ✅ Certificates | **done**: `NativeCertificateReader` default, CLI fallback for legacy only, `CertificateVault` (fixes finding 14 above), `TemporaryFile`, `DebugCertificate` in `Testing/`, global helpers removed. 63 tests, no skips | n/a |
| 7 | ✅ Signing | **done**: `IncrementalSigner` bound to `PdfSigner`, `PendingSignature` + `SignedPdf`, FPDI and TCPDF dropped, disk round-trip gone. **No `TcLibPdfSigner`/`TcpdfSigner` pair**: 7c swapped tc-lib-pdf for tc-lib-pdf-sign, so the package no longer renders PDFs and the core fonts of the fonts blocker below became moot, so `K_PATH_FONTS` is deliberately left undefined | n/a |
| 7b | ✅ Multi-signature | **done**: `Incremental/*` from PoC 0b, the fallback path: with tc-lib-pdf gone there was no `appendIncrementalRevision()` left to inherit. Closes TCPDF#430; `samples/` carries a six-signature document. `approval()` / `certify()` / `ltv()` were **not** built: the level is chosen by `profile()`, and `timestamp()` survives as its shorthand | n/a |
| 7c | ✅ PAdES profiles | **done**: CAdES builder replaces openssl_pkcs7_sign, B-B/B-T live, tc-lib-pdf swapped for tc-lib-pdf-sign (13 deps → 1) | n/a |
| 7d | ✅ DSS | **done**: B-LT, store in its own revision | n/a |
| 7e | ✅ DocTimeStamp | **done**: B-LTA, archive timestamp over the whole file | n/a |
| 8 | ✅ Seal | **done**: `InterventionSealRenderer` on Intervention Image v3 rather than the tc-lib-pdf API, which left with 7c. In-memory seal, driver/font/colour/background from config, `SealPlacement` + `Incremental\SealAppearance` stamping the widget | n/a |
| 9 | ✅ Validation | **done**: signatures verified cryptographically, all of them reported, text parsing gone | n/a |
| 10 | ✅ Mutation | **done**: 71.7% covered-MSI, gate at 70; found the ASN.1 boundary gaps | n/a |
| 11 | ✅ Cleanup + tooling | **done**: `ManageCert` retired, dependency-analyser and normalize wired in, README. Roave BC check deferred: it compares against a released tag, so it only becomes meaningful from 2.0.0 | n/a |
| 12 | ✅ Hardening | **done**: Laravel floor raised to 13, once Pest 5 and Laravel 12 proved uninstallable together ([0005](../decisions/0005-php-and-laravel-floor.md)); `ProcessRunner` rebuilt on `Illuminate\Process\Factory`, with the shell-out arch rule widened to match ([0001](../decisions/0001-openssl-native-with-cli-fallback.md)); mutation widened to `src/Signing/` and the `--covered-min` → `--min` flag corrected ([the quality policy](../spec/quality-policy.md)); Husky pre-commit hook adopted for Pint ([the quality policy](../spec/quality-policy.md)) | n/a |
| 13 | ✅ PEM certificates | **done**: `certificatePem()` / `certificateFromPem()` / `signFromPem()` onto the existing pipeline, `PemCertificateReader`, and the `check_private_key` fix they depended on. `pdf:sign` routes by content and takes `--key`; the vault detects the encoding; `.gitignore` covers `*.pem` / `*.key` and `samples/certificate.pem` is the PFX's own identity re-encoded. Verified in poppler ([0007](../decisions/0007-pem-second-entry-one-pipeline.md)) | n/a |

**PR 0 comes before everything else** and is a throwaway proof of concept, not production
code. It answers the one question that could invalidate the plan: does tc-lib-pdf deliver LTV
and timestamping in practice, and not just in a docblock? Until that answer exists, PRs 7 and
8 are speculation.

PRs 1–4 can land immediately without touching behaviour, and **order matters**: the
toolchain (2–3) precedes the refactor so that arch tests, PHPStan and mutation act as a
safety net for PRs 5–9 rather than as a post-hoc audit.

PR 6 needs the most care. Before it, freeze the current suite as a baseline, including a
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
   certificate to say anything meaningful about trust: poppler already reports
   *Certificate issuer isn't Trusted* for the self-signed test certificate, which is correct
   and expected. `pdfsig` cannot verify `ETSI.RFC3161` document timestamps either, so that
   part rests on the OpenSSL check above.
2. **Roave BC check is not wired in.** It compares the public API against a released tag;
   with 2.0.0 unreleased and everything breaking against `^1`, it would only produce noise.
   It becomes meaningful, and should be added, once 2.0.0 ships.

---
