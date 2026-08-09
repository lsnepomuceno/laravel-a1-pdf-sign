# 0009: Cross-reference streams

**Status:** implemented. Reading landed first and writing followed, and the
measurements below are why the two were separated rather than shipped together.

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

## Decision

Read cross-reference streams, and append one when the document already uses one.
The two were treated as separate questions, and the measurements below are why
that mattered.

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

Signing therefore refused for one release while reading succeeded, with
`DocumentInfo::$usesXrefStream` carrying the distinction, so the message could
say the document can be read but not yet appended to: the true state.

That answered the choice this record had framed. **Option 2, the classic table
with `/Prev` into a stream, is not viable on its own.** Option 1 was built.

## What writing does

`Signing\Incremental\XrefStreamWriter` emits the section, and
`RevisionWriter::crossReference()` picks the form from what the document already
uses. Both writing paths go through it: the signature revision and the
`appendObjects()` revisions that carry the Document Security Store and the
archive timestamp.

Three choices are worth recording, because each had an alternative:

- **The stream indexes itself.** It is an ordinary object, so its own number and
  offset belong in the table it carries. `/Size` is already one past the highest
  number in the revision, which makes it exactly the number the stream takes.
- **No `/Filter`.** A revision indexes a handful of objects, so its table is tens
  of bytes and zlib's header and checksum would make the stream *larger* than
  what they compress. Leaving it raw also removes a failure path, since there is
  no compression call that can fail, and the bytes stay readable in a hex dump.
- **`/Index` carries runs, not one range.** A revision touches the catalog and a
  page low in the file and writes its new objects high in it, so the numbers are
  never one unbroken run. `XrefSubsections` computes the runs and now serves both
  forms, since the classic table's `first count` header says the same thing.

### The bug this uncovered, which was not about streams at all

The first signed output still read as unsigned, for a different reason.
`DocumentReader::findFirstPage()` scanned a fixed 400-byte window from each
object's offset, and in a compact document that window **reaches the objects
that follow**. The catalog was reported as the first page because a `/Type/Page`
two objects later fell inside its window. The revision then wrote `/AcroForm`
onto object 1 and `/Annots` onto object 1, the second silently dropping the
first, so the document had a signature dictionary and no form to reach it from.

The window is now bounded at `endobj`. **This was never a cross-reference-stream
defect**; it was latent in every document whose objects sit close together, and
only a 434-byte fixture was small enough to expose it.

## Verified

`poc/sign-xref-stream.php` produces the artefacts; `pdfsig` reads them.

| Case | poppler |
|---|---|
| Signed once | Signature #1, valid, *Total document signed* |
| Signed twice | #1 valid and *not* total, #2 valid and total |
| B-LTA | #1 valid, #2 `Timestamp2` covering the whole file |

The B-LTA output is indistinguishable from `samples/pades-b-lta.pdf`, down to
poppler's *Unimplemented Feature (0)* on the DocTimeStamp, which the
classic-table sample has always reported too.

`samples/xref-stream.pdf` is the committed artefact, signed twice.

## The boundary tests

`tests/Resources/xref-stream.pdf` is committed, hand-built and 434 bytes.

Both boundary tests did what they were written to do: the reading one failed the
moment reading landed, and the refusal one failed the moment writing landed.
Each had to be rewritten deliberately, which is the point of pinning a limit by
message rather than leaving it undocumented.
