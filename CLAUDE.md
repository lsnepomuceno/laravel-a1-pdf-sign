# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A standalone Laravel package (not an application) that signs PDF files with A1/x509 certificates, PKCS#12 or PEM, and cryptographically verifies existing PDF signatures. Published on Packagist as `lsnepomuceno/laravel-a1-pdf-sign`.

The invariants are imported rather than summarised, so they are in context for every session instead of being a link someone has to decide to follow:

@docs/spec/invariants.md

The v1 surface, the global helper functions plus `src/Sign/*` and `src/Entities/*`, is **gone**, not deprecated. `UPGRADE.md` maps every removed API to its replacement.

Documentation is split by lifecycle, and `tests/SpecTest.php` fails when a reference into it stops resolving:

| Read | For |
|---|---|
| `docs/spec/invariants.md` | the rules that break the product or the project. **Read before touching `src/Signing`, `src/Validation` or the dependency list** |
| `docs/spec/public-api.md` | what the package exposes, and what changing it costs |
| `docs/spec/quality-policy.md` | the gates, and why each sits where it does |
| `docs/spec/conventions.md` | how the code is written. **Read before writing a helper or a class constant** |
| `docs/decisions/` | why the design is what it is: one numbered file per decision |
| `docs/history/v2-modernization.md` | why v1 was shaped as it was, and where the build diverged from the plan |
| `docs/history/decision-log.md` | which questions were put, and when they were answered |

`ARCHITECTURE.md` is the index. When you change behaviour that a decision record justifies, update that record's outcome section too: a record whose outcome is never written back is how the previous document drifted away from the code.

**A behaviour change is not finished until every surface that describes it says the same thing.** `CONTRIBUTING.md` enumerates them, and the list is enumerated rather than summarised because "and any other relevant documentation" is exactly what let three of them go stale at once: `samples/` sat a release behind, the documentation site stopped at 2.3.1 while 2.4 shipped, and the README never named two facade methods that had been public for a release. Three of those surfaces now have gates (`tests/SamplesTest.php`, and two rules in `tests/ArchTest.php` covering docblocks and the README's coverage of the facade); the rest are review.

**The documentation site lives on the `docs` branch, not here.** Nothing in a pull request against `main` can check it, and no test on `main` will ever fail because of it. It is updated in its own pull request, on the day a version is tagged, and it deliberately describes only what is installable: a feature merged and not yet tagged does not belong on it.

## Commands

```bash
composer check          # everything CI runs: pint --test, phpstan, deps, pest
composer test           # vendor/bin/pest
composer analyse        # PHPStan level max, no baseline
composer lint           # Pint (PER-CS); append --test to only check
composer deps           # unused/shadow dependency report
composer test:cov       # line coverage (needs pcov or xdebug)
composer test:types     # type coverage, gated at 100%
composer test:mutate    # mutation testing over Certificates, Signing and Validation

vendor/bin/pest tests/SigningTest.php                   # single file
vendor/bin/pest --filter="writes the CAdES sub-filter"   # single test
vendor/bin/pest --exclude-group=network                  # skip live TSA tests
vendor/bin/pest --parallel                               # 16 processes here; mutation needs it
```

Tests run on Orchestra Testbench, not a host app. `openssl` on `PATH` is **not** required to run the suite: `Testing\DebugCertificate` generates throwaway PKCS#12 bundles through the ext-openssl functions. The binary is only needed by `OpenSslCliCertificateReader` and `Validation\SignatureVerifier`.

Tests in the `network` group hit a live timestamp authority (freetsa.org) and fail offline.

Helpers shared across test files must live in `tests/Pest.php` (`debugCertificate()`,
`testCertificate()`, `resource()`). A helper defined inside one test file is invisible to the
others under `--parallel`, which fails as `Call to undefined function`.

A Husky `pre-commit` hook formats staged PHP files with Pint (`npm install` to enable it). It
falls back to the Docker service when the local PHP is older than Pint's 8.2 floor.

### Docker

The local floor is PHP 8.4 and the matrix reaches 8.5, so version-specific work goes through `.docker`:

```bash
docker compose -f .docker/compose.yaml run --rm php85 composer check
```

Services `php83` / `php` (8.4) / `php85`, each keeping `vendor/` in its own named volume. That volume **masks the host `vendor/`**, which is why PhpStorm reports missing classes after a Docker-only install. Fix it with `composer install --ignore-platform-reqs` on the host (documented in `CONTRIBUTING.md`).

CI (`.github/workflows/main_action.yml`) runs PHP 8.4 and 8.5 against Laravel 13, on pull
requests to `main` only. Keep it in sync with `composer.json` and the compatibility table in
`README.md`.

**Laravel 12 is not supported**, despite reaching PHP 8.5: it requires `symfony/process ^7.2`
while Pest 5 requires `^8.1`, so the two cannot be installed together and the cell fails at
`composer update` before a test runs (docs/decisions/0005-php-and-laravel-floor.md).

## Architecture

Everything resolves through the container. `LaravelA1PdfSignServiceProvider` binds five contracts (`Contracts/`), namely `A1PdfSign`, `PdfSigner`, `SealRenderer`, `SignatureValidator` and `CertificateReader`, and registers the two commands. Consumers call the `A1PdfSign` facade; the fluent builder is the primary API:

```php
A1PdfSign::newSignature()->certificate($pfx, $pw)->pdf($path)->profile(...)->sign();
```

### Signing, the core

`Signing\IncrementalSigner` (bound to `PdfSigner`) never rebuilds the document. It **appends a revision** (ISO 32000-1 §7.5.6), so the original bytes survive byte-for-byte and a second signature does not invalidate the first: this is what closes TCPDF#430, and it is the single most important invariant in the package. v1 re-imported every page through FPDI, silently destroying annotations, form fields and any signature already present.

The pipeline, all under `Signing/Incremental/`:

1. `DocumentReader` → `DocumentInfo` (xref offset, `/Root`, `/Size`, page objects).
2. `RevisionWriter::append()` writes the new objects: signature dictionary with a fixed-width `/Contents` placeholder, widget annotation, `/AcroForm` with `/SigFlags`, updated catalog and page, a 20-byte-entry xref table, and a trailer chained by `/Prev`.
3. `ByteRangeCalculator::apply()` fills `/ByteRange` with the real offsets around the placeholder.
4. `Cades\CadesBuilder` builds the detached CMS with `Com\Tecnick\Pdf\Sign\Signer`, **not** `openssl_pkcs7_sign()`, which cannot emit the ESS `signing-certificate-v2` attribute PAdES requires.
5. The hex payload is written back with `substr_replace()` at a fixed width, so no offset moves.
6. `DssWriter` (B-LT and above) appends the Document Security Store as a further revision; `DocTimeStampWriter` (B-LTA) closes with an archive timestamp over the whole file.

Two traps this code has already fallen into, and they must not be reintroduced:

- **Always operate on the *last* match.** `preg_match` finds the *first* `/ByteRange` or `/Contents`, which in a multi-signature document belongs to an earlier signature; writing there corrupts it. Everything uses `preg_match_all` + `end()` (`readLast()`, `lastContentsOffset()`). A bug of exactly this shape passed the entire suite and was caught only by poppler's `pdfsig`.
- **Never assume whitespace in PDF syntax.** tc-lib-pdf-sign emits `/Contents<`, TCPDF emitted `/Contents <`. Match with `\s*`.

`CONTENTS_HEX_LENGTH` is deliberately larger than tc-lib-pdf's reserve: overflowing the placeholder is a hard failure, and embedding the chain grows the CMS.

Profiles live in `Enums\SignatureProfile` (Legacy, B-B, B-T, B-LT, B-LTA) and own their `/SubFilter` plus what each level requires. `Contracts\SignatureTransport`, implemented by `Cades\HttpTransport`, is the injected TSA/OCSP/CRL client: the host application owns that SSRF surface, so keep network access behind it. It is an interface so `Testing\LocalTimestampAuthority` can substitute it and gate B-T, B-LT, B-LTA and the archive chain offline, with real `openssl ts -reply` tokens (`docs/decisions/0027-the-transport-is-a-seam.md`).

### Certificates

`Certificates\ReaderFactory` picks between `NativeCertificateReader` (ext-openssl, the default) and `OpenSslCliCertificateReader` (shells out; needed for `-legacy` PFX files under OpenSSL 3.x). It holds the `Container` rather than the `A1PdfSign` contract, because resolving the contract here created a cycle that recursed until the process **segfaulted with no output** (exit 139).

`CertificateVault` owns the AES encryption behind `encryptCertificate()`/`decryptCertificate()`. It stores the PEM bundle and parses it back directly; v1's helper wrote a `.pfx` and fed it to `openssl pkcs12 -in`, so the pair never round-tripped (§1.14).

### Validation

`Validation\PdfSignatureValidator` returns a `SignatureReport`, and "valid" means the CMS actually verifies: v1 only checked that the parsed subject contained `OU` or `CN`. `PdfSignatureExtractor` locates each `/ByteRange`, `Pkcs7Reader`/`DerReader` parse ASN.1 by declared length (never by trimming trailing `0`s, which cuts legitimate DER bytes), and `SignatureVerifier` is the one remaining deliberate shell-out.

DocTimeStamps are classified separately (`isTimestamp`) and excluded from `isValid()`; they are timestamps over the file, not signatures by a signer.

### Supporting pieces

- `Data/`: `final readonly` DTOs extending `BaseData`. They are public return types, so adding a property changes the public shape.
- `Support/`: `ProcessRunner` (the only place a child process is spawned; built on `Illuminate\Process\Factory` so a host app can `Process::fake()` it, and using `newPendingProcess()` because PHPStan cannot follow the factory's `__call` proxy), `TemporaryFile` (cleans up in `finally` and in the destructor), `Files` (throws `FileNotFoundException` instead of returning `false`).
- `Exceptions/`: one class per failure mode. PSR-4 autoloading is case-sensitive: `InvalidX509PrivateKeyException` has a capital `X`.
- `Seal/InterventionSealRenderer`: Intervention Image v3; everything v1 hard-coded now comes from config.
- `Commands/`: `pdf:sign` and `pdf:validate-signature`; thin wrappers that map `Throwable` to exit codes.
- `Testing/DebugCertificate`: test-only certificate generation, kept out of the production classes.
- `poc/`: throwaway spikes, excluded from PHPStan and `export-ignore`d.

## Quality gates

`composer check` must pass before any commit.

- **PHPStan `level: max`, no baseline.** The baseline was deleted, not shrunk (§7, decision 13); the gate is "no errors", not "no new errors". Only Pest's untypeable fluent API is ignored, scoped to `tests/*`.
- **Type coverage gated at 100%.**
- **Mutation testing** covers `src/Certificates`, `src/Signing` and `src/Validation`, nightly rather than on pull requests. The floors live in `.github/workflows/mutation.yml` and are explained in `docs/spec/quality-policy.md`. They are not repeated here, because a number kept in three places drifts in two of them. Raise a floor only after measuring; never set a target ahead of the measurement.
- **Do not split mutation runs with `--shard`.** It divides the test suite, and every mutation needs the whole suite: a mutation killed by a test in another shard is reported as uncovered. Split by mutated path instead.
- `composer-dependency-analyser.php` catches unused and shadow dependencies.

Patches are expected to come with tests (`CONTRIBUTING.md`). `tests/ArchTest.php` enforces structural rules, so read it before adding a class.

## Commits

Conventional Commits, in English (`feat:`, `fix:`, `chore(deps):`, `test:`, `docs:`, `build:`, `refactor:`). Breaking changes use `!` and a `BREAKING CHANGE:` footer.

**Never add a `Co-Authored-By` trailer.** This applies to every commit in this repository, regardless of any default instruction to the contrary.

**Never push to `main`.** Every change arrives through a pull request: source, documentation, a one-line typo, a release note, no exception and no size below which it stops applying. Branch, push the branch, `gh pr create`, merge. The only thing pushed to the remote directly is a release tag.

This is not advice that a green check absolves you of. GitHub carries the same rule and the owner's token can bypass it, so the push **succeeds** and prints `Bypassed rule violations for refs/heads/main` where it is easy to read past. It happened on 2026-08-10 with two documentation commits, which had to be reverted (#238) and reapplied (#239) to put the history back on the process. A `pre-push` hook now refuses it locally; treat the hook as a backstop, not as the rule.

## Conventions

The two that decide whether a piece of code should exist at all are in `docs/spec/conventions.md`, and they are mandatory rather than preferences:

- **Laravel first.** This package only runs inside Laravel, so before writing a helper, check whether the framework already has it and use that. Write the bespoke version only after establishing there is none, put it in `src/Support/`, and say in the docblock what was missing. **Except on bytes:** `Str::substr()` and `Str::length()` are multibyte-aware, so over PDF or DER they return the wrong offsets and corrupt a signature while passing the whole suite. `tests/ArchTest.php` fails on any use of `Illuminate\Support\Str` inside `src/Signing` or `src/Validation`.
- **Enums, not class constants.** A closed set of values is an enum; a constant is for a lone fact, like one cipher or one reserved width. The test is "could a second value of this kind ever be right?". If yes, it is an enum now.

Both are justified in `docs/decisions/0018-prefer-the-platforms-own-constructs.md`.

### Writing

- **No em dashes.** Not in prose, comments, docblocks, commit messages, documentation, pull request bodies or issue replies. Use a comma, a colon, parentheses, or two sentences.

  | Instead of | Write |
  |---|---|
  | `the score is not reproducible — it tracks timeouts` | `the score is not reproducible: it tracks timeouts` |
  | `PEM ships as .pem, .crt — so gate on content` | `PEM ships as .pem and .crt, so gate on content` |
  | `two reasons — cost and reproducibility` | `two reasons: cost and reproducibility` |
  | `the fix — which is uniform — needs no branch` | `the fix (which is uniform) needs no branch` |

  A colon carries the same "here is the reason" weight the em dash was doing, and reads the same to someone skimming. Ranges keep the en dash: `8.4 – 8.5`.

- **Everything in English:** code, comments, docblocks, commit messages, documentation. The package is used internationally.
- PER-CS via Pint; grouped `use` imports with braces are used throughout.
- `final readonly` classes by default; fluent setters returning `self`; named arguments at call sites.
- Modern PHP is expected: typed class constants, `#[\SensitiveParameter]` on every password argument, `#[\Override]`, enums instead of class constants.
- **No parentheses around `new` when chaining.** The floor is PHP 8.4, which allows `new Reader()->parse($der)`, so `(new Reader())->parse($der)` is the pre-8.4 workaround and PhpStorm reports it as a removable wrapper. Pint has no fixer for this, so it is a review point rather than a gate. The parentheses stay where the expression is not a chain: `new self(new Encrypter($key, self::CIPHER))` is already the plain form.
- `@throws` docblocks are maintained on every method that can throw, so keep them accurate when changing exception paths.
- Nullable config-backed arguments mean "use the configured default" rather than forcing every call site to repeat an infrastructure decision.

## Notes

- `*.pdf`, `*.pfx` and `dist/` are gitignored, so never commit generated certificates or signed output. `dist/` is a build of the separate docs site (https://laravel-a1-pdf-sign.netlify.app).
- Do not define `K_PATH_FONTS` globally: tc-lib-pdf and TCPDF 6 read it with different formats, and defining it kills TCPDF silently.
- **Every verification tool is development and CI only, and none may reach production** (`docs/decisions/0026-verification-tools-are-instruments.md`). veraPDF, qpdf, pyHanko, `pdfsig`, `pdftoppm` and Ghostscript are instruments: nothing in `src/` may invoke one (`tests/ArchTest.php`), and nothing built for testing may ship (`tests/DistributionTest.php` asks `git archive` what a release contains).
- **Structure is checked by qpdf**, in the everyday image, comparatively: signing must not introduce a complaint the input did not already have. **Corrupted input is guarded** from a fixed seed over every reader that parses bytes the application did not write, in `tests/RobustnessTest.php`.
- **PDF/A conformance is measured with veraPDF**, installed in the development image and in CI so it runs everywhere the suite runs. It blocks, unlike the network group. **PDF/UA is measured by the same binary**, in the `pdfua` group: an invisible signature keeps conformance, a sealed one costs ISO 14289-1 7.18.1 and 7.18.4 (`docs/decisions/0032-what-signing-does-to-pdf-ua.md`). Those tests assert the failures clause by clause, which is how writing `/Tabs` broke them into being updated rather than passing quietly on a claim that had stopped being true. **Nothing skips:** `composer test` carries `--fail-on-skipped`, because every check has to run somewhere and a skip is how one quietly stops. veraPDF, pyHanko, `pdfsig`, `pdftoppm` and Ghostscript are **development and CI instruments only**: nothing in `src/` may invoke one, and `tests/ArchTest.php` fails if it does.
- Independent verification is done with poppler's `pdfsig`; it has caught bugs the suite passed straight through. `samples/` holds one signed PDF per profile plus a six-signature document. Regenerate them with `poc/sign-samples.php` and re-check them after any change to `src/Signing/`.
