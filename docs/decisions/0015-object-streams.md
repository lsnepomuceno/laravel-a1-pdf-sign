# 0015: Object streams

**Status:** implemented.

## Context

[0009](0009-cross-reference-streams.md) shipped cross-reference streams and the
2.2 release notes said the package now signs documents produced by Word and by
"print to PDF" in Chrome. **That claim was wider than what shipped**, and this
record exists partly to correct it.

PDF 1.5 introduced two structures, not one. The cross-reference stream of
§7.5.8 replaces the table, and the **object stream** of §7.5.7 packs ordinary
objects into a single compressed stream. They travel together by design:
packing objects is what the stream form of the cross-reference exists to make
indexable, so a producer that emits one emits the other.

0009 read the index and stopped there. `XrefStreamReader` skipped type-2
entries, the ones naming a packed object, with a comment saying the signer
"appends objects rather than rewriting the ones already there".

**That comment was wrong about the signer.** Signing rewrites the catalog, to
register the field on `/AcroForm`, and the page, to add the widget to
`/Annots`. A catalog it cannot read is a document it cannot sign.

### Measured

A minimal PDF 1.5 was hand-built with its catalog, page tree and page packed
into an object stream, which is what a real producer does with them: they are
dictionaries, and a dictionary is exactly what gets packed. Poppler renders it.
The signer did not:

```
InvalidPdfFileException: object 1 is missing from the cross-reference table
```

The refusal is accurate and loud, which is [0008](0008-exceptions-name-the-real-fault.md)
working. It is still a refusal, and it covers **most** documents from the
producers 2.2 claimed to support, not an edge case.

## Decision

Read packed objects, and write them back uncompressed.

**Reading.** `XrefStreamReader` now records type-2 entries as a second map,
object number to the object stream that packs it, and `DocumentInfo` carries it
alongside `$xref`. `ObjectStreamReader` decodes that stream, reads the pair
table before `/First`, and returns the body. `DocumentReader::rawObject()`
resolves either kind, so everything above it is unchanged.

**Writing: nothing is unpacked in place.** The revision writes the objects it
changes at the top level, uncompressed, and a top-level entry in the newer
cross-reference section supersedes the packed one. The original bytes survive
untouched, which is the invariant the whole signer is built on
([the invariants](../spec/invariants.md)), and the packed copy stays in the file
as history.

That is why the two maps have to be **disjoint and evictive**. Walking the
revision chain, a newer type-1 entry removes the object from the compressed map
and a newer type-2 entry removes it from the offsets. Merging them additively
would leave the signer reading a stale packed catalog on the second signature.

## Consequences

- The reach claimed by 2.2 becomes true, which is the point. The 2.2 release
  notes were corrected rather than left to read as they did.
- `findFirstPage()` searches packed objects too, by their decoded body. A page
  dictionary is a prime candidate for packing, so searching only the offsets
  would find no page in exactly the documents this record is about.
- `Support\PdfStream` is shared with `XrefStreamReader`, which had its own copy
  of the filter decoding. One implementation, and the `/Length` handling is
  better for it: an indirect `/Length` is legal, is not resolved, and falls
  back to the `endstream` keyword.
- **Not handled, deliberately:** an object stream compressed with a filter this
  package does not decode, or with a `/DecodeParms` predictor. Both report the
  object as unreadable rather than guessing, and neither appears in the
  documents this was built for.

## The boundary

`tests/Resources/object-stream.pdf` is committed, hand-built and 434 bytes: a
catalog, page tree and page packed into one object stream, indexed by a
cross-reference stream with three type-2 entries.

Verified in poppler, which is where 0009's first attempt was caught lying:
signed once reports one valid signature covering the whole document, signed
twice reports both, with the first correctly not covering the second's
revision.
