# 0009: Cross-reference streams

**Status:** proposed. The measurement below is done and the rejection is
already correct; reading support is not built.

## Context

`Signing\Incremental\DocumentReader` reads the classic cross-reference table of
ISO 32000-1 §7.5.4. Cross-reference streams, §7.5.8, introduced in PDF 1.5 in
2003, are detected and rejected.

**This bounds who can use the package, and the bound is not small.** Documents
produced by Word, by "print to PDF" in Chrome, by LaTeX with compression, by
Ghostscript in several configurations and by most modern generators use
cross-reference streams. A consumer holding one of those cannot sign it.

### Measured

A minimal PDF 1.5 carrying a `/Type /XRef` stream was hand-built and handed to
the signer. The rejection is accurate and points at the offset:

```
cross-reference stream at offset 281 is not supported; only classic tables are read
```

Before [0008](0008-exceptions-name-the-real-fault.md) that sentence arrived
wrapped in "Invalid file extension", which is why this was first mistaken for a
detection gap. It was a wording gap. **The reader does the right thing and says
the right thing; it simply does not read the format.**

## Decision, proposed

Read cross-reference streams. Writing them is a separate question, answered
below, and the two should not be conflated.

**Reading** means: recognise `/Type /XRef`, decode the stream through its
`/Filter`, walk entries by the widths in `/W`, honour `/Index` when present, and
resolve type-2 entries that live inside an object stream (`/Type /ObjStm`). The
existing `DocumentInfo` shape is unchanged, since it already carries a map of
object number to offset; what changes is how that map is filled.

**Writing** is where care is needed and where a naive implementation produces
files that readers reject. An incremental update appended to a document whose
latest section is a cross-reference stream cannot simply append a classic table:
a reader that starts at the new `startxref` finds a table, follows `/Prev` into
a stream, and PDF 1.5 does not define that mixture except through the hybrid
`/XRefStm` mechanism of §7.5.8.4.

Two options, and the choice should be measured rather than assumed:

1. **Append a cross-reference stream** when the document already uses one. Most
   correct, and it means emitting a stream object whose contents index the
   revision, including itself.
2. **Append a classic table plus `/XRefStm`**, the hybrid form. Simpler to write
   and readable by PDF 1.4 consumers, at the cost of writing both structures.

Whichever is chosen, the invariant that the original bytes survive byte for byte
is untouched: this is about the shape of what gets appended, not about
rewriting.

## Consequences

- The reach of the package changes materially, which is the whole point.
- `poc/incremental-signature/` recorded the same limitation when it was a spike.
  That note stops being a scope statement and becomes a to-do.
- Verification must go beyond the suite. A signed document built on a
  cross-reference stream has to be checked in poppler and in a reader that
  predates PDF 1.5 tolerance, because the failure mode is "opens here, refuses
  there" rather than an exception.

## Until it is built

`tests/Resources/xref-stream.pdf` is committed and a test asserts the current
rejection by message, not only by class. That keeps the boundary honest: if
someone implements reading, the test fails and has to be rewritten
deliberately rather than silently passing.
