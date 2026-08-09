# 0009: Cross-reference streams

**Status:** partially implemented. Reading is built. Writing is not, and the
measurement below says why that distinction had to be enforced rather than
assumed.

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

## What the writing measurement said

Reading landed first, and signing was then tried on the fixture to answer the
question above rather than guess at it. It appeared to work:

```
ASSINOU: 17376 bytes
```

poppler disagreed:

```
File '.output/xref-signed.pdf' does not contain any signatures
```

**So appending a classic table to a document whose latest section is a stream
produces a file that no reader sees as signed, and produces it silently.** That
is worse than the refusal it replaced, and it is the failure mode
[0014](0014-refuse-encrypted-documents.md) exists to prevent: a guard is not a
substitute for the feature, but silence is worse than either.

Signing therefore refuses while reading succeeds. `DocumentInfo` carries
`usesXrefStream` so the signer can tell, and the message says the document can
be read but not yet appended to, which is the true state.

The remaining work is the writing choice this record already framed, now with
evidence that option 2, the classic table with `/Prev` into a stream, is not
viable on its own.

## The boundary tests

`tests/Resources/xref-stream.pdf` is committed, hand-built and 434 bytes.

The test that asserted the old refusal did exactly what it was written to do:
it failed the moment reading landed, and had to be rewritten deliberately. A
second test now pins the signing refusal the same way, by message, so whoever
builds the writing half has to come here and change it on purpose.
