# Invariants

Rules that break the product, or the project, when violated. Short on purpose —
this file is meant to be read whole before touching `src/Signing`, `src/Validation`
or the dependency list.

Everything here is enforced by a test, a tool, or an explicit review step. Where
it is not, that is noted.

---

## 1. `ddn/sapp` is never depended on, and never copied from

**`ddn/sapp` is LGPL-3.0-or-later; this package is MIT.**

Porting or adapting SAPP code into `src/` is a licence violation — an adapted
excerpt is still a derivative work and would drag the whole package into LGPL.

Studying the technique is legitimate: algorithms and file-format mechanics are
not protected by copyright, and incremental update is specified in ISO 32000-1
§7.5.6 and §12.8, a public standard. The implementation is clean-room, written
from that standard. In practice: keep ISO 32000-1 open, not `vendor/ddn/sapp`.

**It is not taken as a dependency either** — not in `require`, not in
`require-dev`, not as `suggest`. That would be legal, since LGPL permits library
use without contaminating the consumer, but it is ruled out: it is a legacy
project and we would inherit its maintenance.

*Enforced by* `tests/ArchTest.php` (`no trace of SAPP`) and
`composer-dependency-analyser.php`.

---

## 2. Signing appends a revision — it never rebuilds the document

`Signing\IncrementalSigner` writes a new revision onto the end of the file
(ISO 32000-1 §7.5.6). The original bytes survive byte for byte.

This is the single most important behaviour in the package. It is what keeps
annotations, form fields and every earlier signature intact, and it is what
closes [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430). v1 re-imported
every page through FPDI and silently destroyed all three.

Any change that makes signing produce a document rather than extend one is a
regression regardless of what the tests say.

*Enforced by* the multi-signature tests, and independently by poppler's `pdfsig`
on `samples/six-signatures.pdf`.

---

## 3. Always operate on the *last* match

`preg_match` finds the **first** `/ByteRange` or `/Contents`, which in a
multi-signature document belongs to an **earlier signature**. Writing there
corrupts it.

Every read of those structures uses `preg_match_all` + `end()` — `readLast()`,
`lastContentsOffset()`.

A bug of exactly this shape passed the entire suite and was caught only by
`pdfsig`: the archive-timestamp revision located the *signature's* placeholder
and overwrote it.

*Enforced by* review and by the poppler cross-check. The suite alone did not
catch it once.

---

## 4. Never assume whitespace in PDF syntax

tc-lib-pdf-sign emits `/Contents<`. TCPDF emitted `/Contents <`. Both are valid.

Match with `\s*`. A literal `'/Contents <'` is the exact form of the defect in
rule 3.

---

## 5. Parse ASN.1 by declared length, never by trimming

`Validation\DerReader` and `Pkcs7Reader` read each structure by the length its
header declares. Trimming trailing `0` bytes cuts legitimate DER.

---

## 6. `K_PATH_FONTS` stays undefined

tc-lib-pdf and TCPDF 6 read it in different formats, and defining it globally
**kills TCPDF silently** — no error, no output.

The package appends revisions to bytes it already has and never emits a
document, so no font definition is ever loaded and nothing needs the constant.

---

## 7. `Certificates\ReaderFactory` holds the container, not the `A1PdfSign` contract

Resolving the contract inside the factory creates a cycle that recurses until
the process **segfaults with no output** (exit 139) — no exception, no stack
trace, nothing to read.

---

## 8. Only `Support\ProcessRunner` spawns a child process

Every shell-out goes through the one audited helper, built on
`Illuminate\Process\Factory` so a consuming application can `Process::fake()` it.

Two places legitimately reach a process, both through the runner:
`Certificates\OpenSslCliCertificateReader` (legacy PFX under OpenSSL 3.x) and
`Validation\SignatureVerifier`.

*Enforced by* `tests/ArchTest.php` (`only the shell helper opens processes`).

---

## 9. Network access stays behind the injected transport

`Signing\Cades\HttpTransport` is the TSA / OCSP / CRL client. The host
application owns that SSRF surface, so nothing else in `src/` opens a
connection.

---

## 10. PSR-4 autoloading is case-sensitive

`InvalidX509PrivateKeyException` has a capital `X`. A file named
`Invalidx509...` autoloads on macOS and fails in production.
