# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**A Laravel adapter over [`lsnepomuceno/signet-pdf`](https://github.com/lsnepomuceno/signet-pdf), and nothing else.**

The engine that signs PDF files with A1/x509 certificates, appends revisions, builds the CAdES, writes the security store and validates signatures is that package. It is framework free, and it is where every byte of PDF is produced or read.

What lives here is the half a Laravel application needs and a standalone package cannot have: the container wiring, a config file, four adapters putting Laravel's own infrastructure behind signet's contracts, three signing entry points that take framework types, six artisan commands and a fake.

The reasoning is imported rather than summarised, so it is in context for every session:

@docs/decisions/0039-the-core-lives-in-signet-pdf.md

**The v2 surface is gone, not deprecated.** Every `Data`, `Enums`, `Exceptions`, `Validation`, `Signing`, `Certificates`, `Seal` and `Support` class now lives under `LSNepomuceno\Signet\`. `UPGRADE.md` maps every one of them.

| Read | For |
|---|---|
| `docs/spec/invariants.md` | the four rules that still belong to this package |
| `docs/spec/public-api.md` | what this package exposes, and what changing it costs |
| `docs/spec/quality-policy.md` | the gates, and why each sits where it does |
| `docs/spec/conventions.md` | how the code is written |
| `docs/decisions/` | why the design is what it is: one numbered file per decision |
| `docs/history/` | how the package got here |

`ARCHITECTURE.md` is the index. When you change behaviour a decision record justifies, update that record's outcome section too.

**Anything about signing itself belongs in signet-pdf.** A defect in the revision writer, the CAdES builder, validation or the seal is fixed there and arrives here as a dependency bump. The temptation to fix it locally is the thing decision 0039 exists to refuse.

## Commands

```bash
composer check          # everything CI runs: pint --test, phpstan, deps, pest, type coverage
composer test           # vendor/bin/pest --fail-on-skipped
composer analyse        # PHPStan level max, no baseline
composer lint           # Pint (PER-CS); append --test to only check
composer deps           # unused/shadow dependency report
composer test:cov       # line coverage (needs pcov or xdebug)
composer test:types     # type coverage, gated at 100%

vendor/bin/pest tests/Adapters/TransportTest.php          # single file
vendor/bin/pest --filter="Http::fake"                     # single test
```

Tests run on Orchestra Testbench, not a host app. `openssl` on `PATH` **is** needed: `Signet\Testing\DebugCertificate` generates throwaway PKCS#12 bundles through ext-openssl, and validation shells out to the binary.

Shared helpers live in `tests/Pest.php` (`debugCertificate()`, `pemCertificate()`, `testCertificate()`, `resource()`, `packageRoot()`). A helper defined inside one test file is invisible to the others under `--parallel`, which fails as `Call to undefined function`.

A Husky `pre-commit` hook formats staged PHP files with Pint and runs PHPStan (`npm install` to enable it). It runs on the **host**, so the host needs `vendor/`: `composer install --ignore-platform-reqs`. The Docker services keep their own `vendor/` in a named volume that masks the host one, which is why PhpStorm reports missing classes after a Docker-only install.

### Docker

```bash
docker compose -f .docker/compose.yaml run --rm php85 composer check
```

Services `php83` / `php` (8.4) / `php85`. The image carries no verification instruments: veraPDF, qpdf, pyHanko and the Arlington model measure written bytes, and this package writes none.

CI (`.github/workflows/main_action.yml`) runs PHP 8.4 and 8.5 against Laravel 13, on pull requests to `main` and to `feat/v3` while the 3.0 line is in progress. Keep it in sync with `composer.json` and the compatibility table in `README.md`.

## Architecture

Everything resolves through the container. `LaravelA1PdfSignServiceProvider` builds one `Signet\Signet` from the config file, injecting the Laravel adapters, and binds signet's contracts as accessors on it so that replacing the engine replaces everything.

```php
A1PdfSign::newSignature()->certificate($pfx, $pw)->pdf($path)->profile(...)->sign();
```

### The four adapters, and why each exists

| `src/Adapters/` | Replaces | Because |
|---|---|---|
| `IlluminateProcessRunner` | `Support\SymfonyProcessRunner` | a class built inline cannot be faked, so `Process::fake()` would silently stop covering the CLI certificate reader and the signature verifier |
| `IlluminateEncrypter` | the vault's `SodiumEncrypter` default | the application audits `illuminate/encryption`, and 2.x material keeps opening |
| `IlluminateSignatureTransport` | `Cades\HttpTransport` | the host owns the SSRF surface, and here that means `Http::fake()`, `preventStrayRequests()`, its proxy and its logging |
| `src/Io/DiskSource`, `DiskDestination`, `UploadedFileSource` | nothing | new: signing a document that lives on S3 without pulling it into a local file |

**The first is not a convenience.** It is invariant 8 surviving the extraction. `tests/Console/CheckEnvironmentTest.php` is what proves it.

### The rest of `src/`

- `A1PdfSignManager`: delegates everything that touches a byte. What it implements is `signFromUpload()`, `encryptCertificate()` from an upload, the disk helpers and `tempPath()`.
- `Config\SignetConfigFactory`: `config/a1-pdf-sign.php` into `Signet\Config\SignetConfig`. **The config file stays an array of scalars**, so `config:cache` keeps working; the objects are built at resolution. A bad value fails at boot naming its key.
- `Commands/`: six thin artisan commands mapping `Throwable` to an exit code. Typed input comes from `Commands\Concerns\ReadsTypedInput`.
- `Testing\A1PdfSignFake`: installs signet's recorder into the container by rebuilding the engine, not by rebinding `PdfSigner`. Rebinding alone would leave `newSignature()->…->sign()` reaching the real signer.

## Quality gates

`composer check` must pass before any commit, and it now includes type coverage: a gate CI runs and the documented local command does not is a gate discovered on a pull request.

- **PHPStan `level: max`, no baseline.** The gate is "no errors", not "no new errors".
- **Type coverage gated at 100%.**
- **Dead code is refused**, by PHPStan plus `tests/Project/DeadCodeTest.php` walking the tree with `token_get_all()`. Unused public methods are deliberately not checked: the API exists for consumers whose code is not in this repository.
- **No mutation testing here.** It covered `Certificates`, `Signing` and `Validation`, and all three left. It runs in signet-pdf, split by mutated path.
- **No conformance, structure or robustness gates here**, for the same reason (`docs/decisions/0039-the-core-lives-in-signet-pdf.md`).
- `composer-dependency-analyser.php` catches unused and shadow dependencies.

Patches are expected to come with tests. `tests/Project/ArchTest.php` enforces structural rules, so read it before adding a class.

## Commits

Conventional Commits, in English (`feat:`, `fix:`, `chore(deps):`, `test:`, `docs:`, `build:`, `refactor:`). Breaking changes use `!` and a `BREAKING CHANGE:` footer.

**Never add a `Co-Authored-By` trailer.** This applies to every commit in this repository, regardless of any default instruction to the contrary.

**Never push to `main`.** Every change arrives through a pull request: source, documentation, a one-line typo, a release note, no exception and no size below which it stops applying. The only thing pushed to the remote directly is a release tag.

This is not advice that a green check absolves you of. GitHub carries the same rule and the owner's token can bypass it, so the push **succeeds** and prints `Bypassed rule violations for refs/heads/main` where it is easy to read past. It happened on 2026-08-10 with two documentation commits, which had to be reverted (#238) and reapplied (#239). A `pre-push` hook now refuses it locally; treat the hook as a backstop, not as the rule.

## Conventions

- **Laravel first.** This package only runs inside Laravel, so before writing a helper, check whether the framework already has it. That is the whole job here: where signet-pdf had to build something because it has no framework, this package should be using the framework's.
- **Enums, not class constants.** A closed set of values is an enum; a constant is for a lone fact.
- **No em dashes.** Not in prose, comments, docblocks, commit messages, documentation, pull request bodies or issue replies. Use a comma, a colon, parentheses, or two sentences. Ranges keep the en dash: `8.4 – 8.5`.
- **Everything in English:** code, comments, docblocks, commit messages, documentation.
- PER-CS via Pint; grouped `use` imports with braces are used throughout.
- `final readonly` classes by default; fluent setters returning `self`; named arguments at call sites.
- Modern PHP is expected: typed class constants, `#[\SensitiveParameter]` on every password argument, `#[\Override]`, enums instead of class constants.
- **Every file declares `strict_types=1`**, enforced twice in `tests/Project/ArchTest.php`.
- **No parentheses around `new` when chaining.** The floor is PHP 8.4, so `new Reader()->parse($der)` is the plain form.
- **Never cite a file that does not exist, and write it first.** `tests/Project/SpecTest.php` walks every `.php`, `.md` and `.yml` and fails on a path that does not resolve. It checks symbols of this package too.
- `@throws` docblocks are maintained on every method that can throw.
- Nullable config-backed arguments mean "use the configured default" rather than forcing every call site to repeat an infrastructure decision.

## Notes

- `*.pdf`, `*.pfx` and `dist/` are gitignored, so never commit generated certificates or signed output.
- The verification instruments may still never be invoked from `src/`, and `tests/Project/ArchTest.php` fails if one is. The rule outlives the tools' presence: it is about what ships, not about what is installed.
- `tests/Project/DistributionTest.php` asks `git archive` what a release actually contains.
