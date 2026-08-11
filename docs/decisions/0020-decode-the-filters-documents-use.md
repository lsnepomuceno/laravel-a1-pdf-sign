# 0020: Decode the filters documents actually use

**Status:** implemented.

## Context

`Support\PdfStream` decoded two filters, `/FlateDecode` and `/ASCIIHexDecode`,
each written as a bare name, and ignored `/DecodeParms` entirely.

That covers what this package writes. It does not cover what it is handed, and
the way it fails is the worst available: an object that cannot be read is an
object the signer will not sign around, so **a document nothing is wrong with
comes back as unsignable**.

Three things were missing and all three are ordinary:

- **a filter chain**, `/Filter [/ASCII85Decode /FlateDecode]`, applied in order;
- **a predictor**, `/DecodeParms <</Predictor 12 /Columns 5>>`. Every modern
  generator compresses its cross-reference stream this way, because consecutive
  rows of a cross-reference table differ from each other by very little;
- **`/LZWDecode`**, which predates Flate and still appears from older tooling.

The predictor is the one that matters. Measured on a three-object PDF 1.5
document whose cross-reference stream uses PNG-Up, the old decoder inflated the
stream correctly and then read the **differences** as the values:

```
xref = {"2": 16908288}
```

One entry, pointing 16 MB into a 379-byte file, with objects 1, 3 and 4 absent
entirely. The catalog is object 1, and signing rewrites the catalog, so the
document was unsignable and the error said so about the wrong thing.

## Decision

**Decode the filter chain, and undo the predictor.**

`Support\PdfFilters` takes the whole `/Filter` and `/DecodeParms` pair and
applies them in order. `Enums\StreamFilter` names the five it implements.

### Null, not the raw bytes

A filter this package does not implement makes the payload `null` rather than
returning it undecoded. Handing back something compressed that happens to
contain `<<` is how a caller ends up parsing noise as objects, and a wrong
object is worse than a missing one everywhere else in this codebase too.

### Predictors only where they mean something

`StreamFilter::takesPredictor()` is true only for Flate and LZW. A `/Predictor`
beside an ASCII filter is not illegal, it is meaningless, and applying one
anyway would corrupt a payload that had decoded correctly.

The PNG filter type is read **per row**, which is what RFC 2083 specifies and
what `/Predictor 15` means: not "filter 15", but "any of them, row by row". The
declared value therefore decides only that a PNG predictor is in play.

### What is deliberately not implemented

- **`/DCTDecode` and `/JPXDecode`.** Streams are read here to find objects, and
  an image is never one. Decoding a JPEG to look for a dictionary in it would be
  work spent to find nothing.
- **`/Crypt`.** Encrypted documents are refused before any of this
  ([0014](0014-refuse-encrypted-documents.md)).
- **A TIFF predictor over sub-byte components.** It needs the samples unpacked
  and repacked, and a producer pairing 4-bit components with a predictor is rare
  enough that guessing is worse than answering null.

### Checked against the standard, not against itself

The LZW test decodes the worked example from ISO 32000-1 §7.4.4.2 table 10, and
gets back `-----A---B`. Round-tripping through an encoder written in the same
hour would only establish that two pieces of code agree with each other.

`/EarlyChange` is honoured for the same reason it exists: it decides whether the
code width grows one code before the dictionary is full, and a decoder ignoring
it produces plausible bytes that are wrong from the first width change onward.

## Consequences

- `Support\PdfStream::decode()` moves to `Support\PdfFilters`, which
  `PdfStream` composes. Both are `@internal`.
- Documents from Word, Chrome's print-to-PDF and LaTeX with compression are read
  where the predictor previously made them unreadable. `tests/Resources/xref-stream-predictor.pdf`
  is committed as the case, and it is signed and validated in the suite.
- Nothing about what the package **writes** changes. `XrefStreamWriter` still
  emits no filter at all, because a revision indexes a handful of objects and
  zlib's header and checksum would make the stream larger than the bytes they
  compress ([0009](0009-cross-reference-streams.md)).

## Alternatives rejected

| | Why not |
|---|---|
| Return the raw bytes for an unimplemented filter | A caller parses compressed noise as objects, confidently |
| Read `/Predictor` as a per-stream filter type | It is per row, and `/Predictor 15` is not a type at all |
| Skip LZW as obsolete | It costs one method and the documents exist |
| Decode `/DCTDecode` for completeness | An image never holds an object worth finding |
| Round-trip LZW through an encoder written here | Establishes agreement, not correctness |
