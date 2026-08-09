# 0006: Sign by appending a revision, written in-package

**Status:** accepted, implemented. The most consequential decision in the
package.

## Context

A second signature destroyed the first, reported as [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430),
open since 2021. v1 imported every page through FPDI and rebuilt the document,
which discarded annotations, form fields and any signature already present.

An earlier draft of the plan claimed tc-lib-pdf solved this through its
`approval` flag. **That was wrong**, and the correction matters because it is
the kind of mistake that looks like a fix.

Tracing the only three usages of `signature['approval']` in tc-lib-pdf, the flag
does exactly one thing: it suppresses the `/Reference << /Type /SigRef … /DocMDP >>`
dictionary and the corresponding `/Perms` entries. It toggles between a
**certification** and an **approval** signature, ISO 32000-1 semantics, nothing
more. Nowhere does it read the original file's bytes to append a revision:
`$startxref = strlen($out)` is computed over the freshly built output.
`Import\Importer` confirms the model: `importPage()` allocates a Form XObject
and clones resources, architecture identical to FPDI's. **That is rebuilding.**

The decisive proof was in this package already: v1's `SignaturePdf` passed `'A'`
to TCPDF's `setSignature()` and had for years. Approval mode was on the whole
time, and the second signature still overwrote the first.

## Decision

Implement incremental update (ISO 32000-1 §7.5.6) in-package. Keep the original
bytes untouched and append a revision containing only the changed objects:

1. Read the original's xref and trailer → previous `startxref`, `/Root`,
   `/Size`, `/AcroForm`.
2. Append a `/Type /Sig` object with `/ByteRange` and a fixed-size `/Contents`
   placeholder, the signature field widget, the updated page, the updated
   `/AcroForm` (`/Fields` + `/SigFlags 3`) and the catalog.
3. Write a new cross-reference section chained by `/Prev`, a new `startxref`,
   `%%EOF`.
4. Compute `/ByteRange` over the whole file except the placeholder, sign it as
   detached CMS, and inject the blob **without shifting a single offset**.

Each signature covers the file up to its own revision, which is how a reader
reports "signature 1 valid, covers revision 1" without invalidating it.

The implementation is **clean-room, from the standard**. `ddn/sapp` is LGPL and
this package is MIT: it is a conceptual reference only, never a dependency and
never a source of code. That constraint is in
[the invariants](../spec/invariants.md), enforced by an arch test and by the
dependency analyser.

## Consequences

- The original bytes survive byte for byte, so annotations, form fields and
  every earlier signature stay intact.
- Output is no longer byte-comparable with 1.x.
- Signature fields must not collide, so each revision gets its own name
  (`Signature1`, `Signature2`, …) unless `fieldName()` overrides it.
- FPDI and TCPDF leave the dependency list.
- The detached CMS is built with `Com\Tecnick\Pdf\Sign\Signer`, **not**
  `openssl_pkcs7_sign()`, which cannot emit the ESS `signing-certificate-v2`
  attribute PAdES requires.

## Two traps this code has already fallen into

Both are in [the invariants](../spec/invariants.md) because both cost real
debugging, and neither was caught by the test suite:

- **Always operate on the last match.** `preg_match` finds the *first*
  `/ByteRange` or `/Contents`, which in a multi-signature document belongs to an
  earlier signature.
- **Never assume whitespace in PDF syntax.** tc-lib-pdf-sign emits `/Contents<`;
  TCPDF emitted `/Contents <`.

## Outcome

Verified with poppler's `pdfsig` on a six-signature document: all six report
*Signature is Valid*, with correctly progressive coverage. `samples/` carries
that document.

`approval()`, `certify()` and `ltv()` were never built as separate builder
methods: the PAdES level chosen by `profile()` determines all three.
