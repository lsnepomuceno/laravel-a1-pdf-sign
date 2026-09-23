# Changelog

Every release, and what it costs to move to it.

This file is the summary. The reasoning behind a change lives in
[docs/decisions/](docs/decisions/README.md), and the mechanics of upgrading
live in [UPGRADE.md](UPGRADE.md), which is where a breaking change is explained
rather than merely listed.

**Semantic versioning, and the public API is what
[docs/spec/public-api.md](docs/spec/public-api.md) says it is.** Adding to it is
a minor release; changing it is a major one.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

AI agents can read signed documents and, with a person's approval of every
call, sign them. Everything here is additive and optional: an application that
installs neither SDK sees no difference
([0040](docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md)).

### Added

- **Two read-only tools for `laravel/mcp`**, `validate_pdf_signature` and
  `list_signature_fields`, grouped in `Mcp\A1PdfSignServer` for an application
  to register in `routes/ai.php`. AI SDK agents run the same tools.
- **A signing tool for `laravel/ai`**, `sign_pdf` (`Ai\Tools\SignPdf`). Every
  call pauses for a person's approval, which cannot be switched off; the
  certificate comes from `Contracts\SigningCertificateResolver`, never from the
  model; a repeated call signs once; a signed copy never overwrites a file.
- **`Events\DocumentSignedByAgent`**, dispatched with the signing receipt once
  an agent's signature is written.
- **`agents.disks`**, **`agents.expose_registry`** and
  **`agents.idempotency.{store,ttl}`** in the config file. No disk is open by
  default, so no tool reaches anything until one is listed.
- `Exceptions\DocumentOutOfReach` and `Exceptions\SigningCertificateUnavailable`,
  both implementing `SignetException`.

### Changed

- `composer.json` suggests `laravel/ai` and `laravel/mcp`, and declares a
  `conflict` with any version of either outside `^1.0`.

### Removed

- The suggestion of `lsnepomuceno/laravel-brazilian-ceps`, which had nothing to
  do with signing.

## [3.0.0]

The release that stopped carrying its own engine.

Everything that signs, validates, reads a certificate or renders a seal is
[`lsnepomuceno/signet-pdf`](https://github.com/lsnepomuceno/signet-pdf) now,
which is the same code extracted and made framework free. What is left here is
the Laravel half, and it is a better half than it was: `Storage` disks as a
signing source, `Http::fake()` reaching the timestamp authority, and every
capability signet-pdf 3.0 grew reachable from the facade.

**Every import changes and nothing else does.** The facade, its methods, their
arguments, every config key, the artisan commands, the fake and the encryption
envelope are what they were. [UPGRADE.md](UPGRADE.md) has the table.

### Added

- **A document can be signed straight from a `Storage` disk and written back to
  one**, with no local file at either end. `fromDisk()`, `toDisk()` and
  `fromUpload()`.
- **Two-phase signing through the facade**: `prepare()` and `complete()`, where
  the private key never enters the process. The prepared signature carries no
  secret, so it survives a queue.
- **`addSignatureField()`**, placing an empty field rather than only filling one
  somebody else placed.
- **`signature.policy`** in the config file, declaring an ICP-Brasil signature
  policy: `ad-rb`, `ad-rt`, `ad-rc`, `ad-ra`, an OID, or the three fields of a
  policy from anywhere else. Naming a family resolves to the version in force.
- **`certificate.chain_paths`**, the intermediates to embed when the bundle
  carries none.
- **Three artisan commands**: `pdf:fields`, `pdf:add-field`, `pdf:extend`.
- **`assertPrepared()` and `assertCompleted()`** on the fake.
- A source is accepted wherever a path was, so a document on S3 can be
  validated without being downloaded.

### Changed

- **Every `Data`, `Enums`, `Exceptions`, `Validation`, `Signing`,
  `Certificates`, `Seal` and `Support` class moved to the
  `LSNepomuceno\Signet\` namespace.**
- The five engine contracts are signet's. `Contracts\A1PdfSign` stays.
- `EncryptedCertificate::$hashKey` is `$hash`.
- `->pdfFromDisk('s3', $path)` is `->from(A1PdfSign::fromDisk('s3', $path))`.
- `composer check` now includes type coverage, which was a CI-only gate.

### Removed

- The engine, and with it the conformance, structure, robustness and mutation
  gates that measured what it wrote. They run in signet-pdf.

### Requires

- **PHP 8.4.1**, up from 8.4.
- **`intervention/image ^4`**, up from `^3.11`, through signet-pdf. An
  application pinned to `^3` cannot install this release.

## Earlier releases

2.7.0 and before are on
[GitHub Releases](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/releases),
and [UPGRADE.md](UPGRADE.md) carries the notes for every one of them.
