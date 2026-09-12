# Invariants

Rules that break the product, or the project, when violated. Short on purpose:
this file is meant to be read whole before touching `src/Adapters`, `src/Io`
or the dependency list.

Everything here is enforced by a test, a tool, or an explicit review step.

**Most of what used to be here is signet-pdf's now.** Appending a revision
rather than rebuilding, operating on the last match, never assuming whitespace
in PDF syntax, parsing ASN.1 by declared length, leaving `K_PATH_FONTS`
undefined: every one of those governs code that no longer lives in this
repository. They are in
[signet-pdf's own invariants](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/spec/invariants.md),
and a change here cannot violate them
([0039](../decisions/0039-the-core-lives-in-signet-pdf.md)).

What follows is what a wrapper can still break.

---

## 1. The adapters are bound, always

`Signet\Signet` resolves its own defaults when nothing is injected:
`Support\SymfonyProcessRunner` and `Signing\Cades\HttpTransport`. Both work.
Both are wrong here.

**A class built inline cannot be faked.** If the provider stops injecting
`Adapters\IlluminateProcessRunner`, `Process::fake()` silently stops covering
the CLI certificate reader and the signature verifier, and no consuming
application's suite reports it. The same holds for
`Adapters\IlluminateSignatureTransport` and `Http::fake()`.

This is the whole reason the package exists rather than a `composer require`
line in the application, so a regression here is not a small one.

*Enforced by* `tests/Console/CheckEnvironmentTest.php`, which asserts that a
faked process is what the environment check sees, and
`tests/Adapters/TransportTest.php`, which asserts the same for HTTP.

---

## 2. Network access stays behind the injected transport

`Signet\Contracts\SignatureTransport` is the TSA, OCSP and CRL client. **The
host application owns that SSRF surface**, and in a Laravel application owning
it means `Http::fake()`, `preventStrayRequests()`, the application's proxy, its
CA bundle, its middleware and its logging.

Nothing else in `src/` opens a connection, and nothing may.

*Enforced by* `tests/Adapters/TransportTest.php`, including the case that says
a `pades-b-b` signature reaches no network at all.

---

## 3. The config file holds scalars, and nothing else

`config/a1-pdf-sign.php` is read by `Config\SignetConfigFactory` and turned
into `Signet\Config\SignetConfig` at resolution time.

**A config file carrying an object cannot be cached.** `config:cache`
serialises the array; an enum instance or a readonly object fails there, and it
fails in the consuming application rather than here, on a command nobody runs
until deployment.

*Enforced by* `tests/Container/ConfigTest.php`.

---

## 4. Only `Adapters\IlluminateProcessRunner` spawns a child process

Every shell-out goes through the one audited adapter, built on
`Illuminate\Process\Factory`. Two callers legitimately reach a process, both
inside signet-pdf and both through the contract: the legacy PFX reader and the
signature verifier.

*Enforced by* `tests/Project/ArchTest.php` (`only the shell adapter opens
processes`).

---

## 5. Verification instruments never reach production

veraPDF, qpdf, `pdfsig`, `pdftoppm`, Ghostscript, pyHanko and Arlington's
`testgrammar` are measuring instruments. Nothing in `src/` may invoke one, and
nothing built for testing may ship.

The rule stays even though the tools are no longer installed anywhere near this
package: it is about what ships, not about what is present
([0026](../decisions/0026-verification-tools-are-instruments.md)).

*Enforced by* `tests/Project/ArchTest.php` and
`tests/Project/DistributionTest.php`, which asks `git archive` what a release
actually contains.

---

## 6. PSR-4 autoloading is case-sensitive

`InvalidX509PrivateKeyException` has a capital `X`. A file named
`Invalidx509...` autoloads on macOS and fails in production.
