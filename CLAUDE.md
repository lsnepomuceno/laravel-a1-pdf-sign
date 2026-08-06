# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A standalone Laravel package (not an application) that signs PDF files with A1/x509 `.pfx` certificates and validates existing PDF signatures. Published on Packagist as `lsnepomuceno/laravel-a1-pdf-sign`. Auto-discovery registers `LaravelA1PdfSignServiceProvider`, whose only job is to register the two Artisan commands; everything else is plain classes plus globally autoloaded helper functions (`composer.json` → `autoload.files`).

## Commands

```bash
composer test                                  # full suite (vendor/bin/testbench package:test)
vendor/bin/testbench package:test --filter=testValidateCertificateStructureFromPfxFile   # single test
vendor/bin/testbench package:test tests/SealImageTest.php                                 # single file
```

Tests run through Orchestra Testbench, not a host Laravel app. `openssl` must be on `PATH` — most tests generate a throwaway certificate via `ManageCert::makeDebugCertificate()` by shelling out to it.

CI (`.github/workflows/main_action.yml`) runs the matrix PHP 8.1–8.4 × Laravel 9–12 on pull requests to `main`, `dev`, `v1.x-dev`. When touching `composer.json` constraints, keep that matrix in sync with the PHP/Laravel compatibility table in `README.md`.

## Architecture

Four collaborating classes under `src/Sign/`, plus thin wrappers:

- **`ManageCert`** — owns certificate state. `fromPfx()` / `fromUpload()` shell out to `openssl pkcs12` to convert the `.pfx` into a combined cert+key PEM string, then `setCertContent()` parses and `validate()`s it (checks the private key actually matches the x509). It also owns an `Illuminate\Encryption\Encrypter` (AES-128-CBC) with a per-instance random hash key, used to encrypt cert blobs for storage. Every other class takes a `ManageCert` instance.
- **`SignaturePdf`** — wraps FPDI/TCPDF (`setasign\Fpdi\Tcpdf\Fpdi`). Re-imports every page of the source PDF into a new document, optionally stamps a seal image, then calls TCPDF's `setSignature()` with the PEM from `ManageCert`. Two output modes: `MODE_RESOURCE` (returns raw bytes, deletes the temp file) and `MODE_DOWNLOAD` (returns a `BinaryFileResponse` with `deleteFileAfterSend()`).
- **`ValidatePdfSignature`** — no PDF library involved. Regex-scans the raw PDF for `ByteRange[...]`, extracts the embedded PKCS#7 blob by byte offset, writes it to a temp file, shells out to `openssl pkcs7 -print_certs`, and parses the resulting plain text into key/value pairs. "Validated" simply means the parsed subject contains `OU` or `CN`.
- **`SealImage`** — draws the certificate's subject/issuer/expiry onto `src/Resources/img/sign-seal.png` using Intervention Image v3 (GD by default, Imagick accepted). Returns JPEG bytes or a data URI.

Two important cross-cutting behaviours:

- **Shelling out to openssl** goes through `runCliCommandProcesses()` in `src/Helpers/helpers.php`, which uses `Symfony\Component\Process` and throws `ProcessRunTimeException` on non-zero exit. The `$usePathEnv` flag that threads through most public APIs decides whether the child process inherits `PATH` — needed in environments where Process's empty default env can't find the `openssl` binary. When adding shell interpolation, follow the existing `escapeshellarg()` usage in `ManageCert`.
- **Temp files** all go through `a1TempDir()`, which prefers `src/Temp/` and falls back to `sys_get_temp_dir()` when that isn't writable. Filenames are `Str::orderedUuid()`. Anything written there should be deleted on the success path; `tests/TestCase::tearDown()` sweeps `src/Temp/` (keeping `.gitkeep`) as a safety net.

### Supporting pieces

- `src/Entities/` — readonly-style DTOs (`CertificateProcessed`, `EncryptedCertificate`, `ValidatedSignedPDF`) extending `BaseEntity`, which implements `Arrayable` via `get_object_vars()`. Public API return types; adding a property changes the public shape.
- `src/Exceptions/` — one exception per failure mode, each building its own message in the constructor and implementing `Stringable`. New failure modes get their own class rather than a generic exception.
- `src/Helpers/helpers.php` — the user-facing procedural API (`signPdfFromFile`, `signPdfFromUpload`, `encryptCertData`, `decryptCertData`, `validatePdfSignature`). Each is guarded by `function_exists()`. These are what most consumers call, so signature changes here are breaking.
- `src/Commands/` — `pdf:sign` and `pdf:validate-signature`, thin wrappers over the helpers that catch `Throwable` and map to exit codes.

## Commits

Conventional Commits, in English, matching the existing history (`feat:`,
`fix:`, `chore(deps):`, `test:`, `docs:`, `build:`, `refactor:`). Breaking
changes use `!` and a `BREAKING CHANGE:` footer.

**Never add a `Co-Authored-By` trailer.** This applies to every commit in this
repository, regardless of any default instruction to the contrary.

## Conventions

- PSR-2 style; grouped `use` imports with braces (`use Illuminate\Support\{Facades\File, Str};`) are used throughout.
- Fluent setters returning `self`/the class name; named arguments used liberally at call sites.
- `@throws` docblocks are maintained on every method that can throw — keep them accurate when changing exception paths.
- SemVer is followed; the public surface is the helper functions, the four `Sign/` classes, the entities, and the command signatures.
- Patches are expected to come with tests (`CONTRIBUTING.md`).

## Notes

- `src/Temp`, `*.pdf`, `*.pfx`, and `composer.lock` are gitignored — don't commit generated certificates or signed output.
- `dist/` is a build of the separate documentation site (https://laravel-a1-pdf-sign.netlify.app) and is gitignored; it is not part of the package.
- `ManageCert::setIsLegacy()` adds openssl's `-legacy` flag, needed for old PFX files under OpenSSL 3.x.
