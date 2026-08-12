# 0031: Certification is verified by a reader that enforces it

**Status:** implemented.

## Context

Certification signatures shipped in 2.2 and
[0012](0012-certification-signatures.md) said outright that their verification
was incomplete:

> `pdfsig` does not surface `/DocMDP`, so whether a reader *enforces* a
> certification is untested here.

Every other claim this package makes has an outside witness. poppler reads the
samples, veraPDF decides PDF/A, qpdf checks structure and reads back what was
encrypted. Certification was the one claim with none, and it is a claim about
what other software will refuse to do.

The tests that existed check what this package **writes**: that `certify()`
emits the `/DocMDP` transform, that `/Perms` names the signature that made it,
that a second certification is refused, that a further signature over a
`no-changes` document is refused. Necessary, and nowhere near sufficient. A
reader that ignores all of it would pass every one of them.

## Decision

**pyHanko is the instrument, and `/DocMDP` is measured in both directions.**

The measurement, on this package's own output:

| Document | pyHanko's verdict |
|---|---|
| Certified at `no-changes`, untouched | "The signature covers the entire file", VALID |
| The same, with one page resized in an appended revision | "incompatible with the current document modification policy", INVALID |
| Certified at `form-filling`, signed again | "compatible with the current document modification policy", VALID |

The middle row is the one that matters. It is not this package agreeing with
itself: pyHanko reads the certification, compares the revisions, and refuses.

### Why pyHanko rather than the ETSI DSS

DSS was the tool named when the gap was first written down, and it was the
wrong choice on inspection. It is a Java library plus a demonstration webapp
with no first-class command line, and it is LGPL-2.1, which sits awkwardly
beside [invariant 1](../spec/invariants.md). pyHanko is MIT, installs with
`pip`, needs no JVM, and its incremental-update diff analysis is the feature
the question was asking for rather than a side effect of one.

Apache PDFBox was considered and rejected for a sharper reason: it **reads**
`/Perms/DocMDP` and does not evaluate revisions against it. Asserting against a
permission value another library parsed is the same thing as asserting against
one we parsed, which is what this record exists to avoid.

### It also reads what we cannot check about ourselves

pyHanko signs as well as validates, which closed a second gap nobody had
named. Every validation test signed with this package first, and every file in
`samples/` is this package's output, so `PdfSignatureExtractor`, `Pkcs7Reader`
and `DerReader` had only ever been shown one producer's bytes.

Pointing the validator at a pyHanko-signed document found two defects on the
first attempt, both of the same shape and both now fixed:

- `/ByteRange\[0 ` was matched literally, so `/ByteRange [0 9875 15069 565]`
  found nothing and a valid document raised as unsigned.
- `/SubFilter` was read from a window *behind* the `/ByteRange`, which is where
  this package writes it. pyHanko writes `/Contents` first, putting
  `/SubFilter` after. The sub-filter came back null, and with it the profile,
  and a `/DocTimeStamp` from that producer would have been classified as a
  signature and reported invalid for failing to verify as one.

[Invariant 4](../spec/invariants.md) covered exactly this and had been written
for the signing side only.

## Consequences

- `tests/CertificationEnforcementTest.php` blocks. pyHanko is deterministic and
  runs offline, so a failure is this package's rather than somebody else's
  outage, which is what separates it from the `network` group.
- `tests/ForeignSignatureTest.php` validates a committed pyHanko-signed
  document, and needs nothing installed to run.
- **The instrument does not ship and cannot be reached from `src/`**
  ([0026](0026-verification-tools-are-instruments.md)). `tests/ArchTest.php`
  gains `pyhanko` in its list of banned literals.
- Both distributions are pinned: `pyHanko` and `pyhanko-cli` are versioned
  separately, and installing the first alone gives no command at all.
- The development image gains a Python runtime and `tzdata`. pyHanko loads a
  `Europe/Brussels` zone at import time for the EU trusted list, and Alpine
  ships no zone data, so without it every invocation dies in an import.
- 0012's verification section is updated, and the caveat in the decisions index
  is removed.

## Alternatives rejected

| | Why not |
|---|---|
| ETSI DSS | JVM, LGPL-2.1, and no first-class CLI |
| Apache PDFBox | Reads the permission, does not evaluate revisions against it |
| Writing our own `/DocMDP` interpreter | The package agreeing with itself, which is the whole failure this closes |
| Asserting the samples are unchanged instead | A frozen artefact cannot fail when the writer changes |
