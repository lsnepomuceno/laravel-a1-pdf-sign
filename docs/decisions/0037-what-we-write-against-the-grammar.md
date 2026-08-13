# 0037: What we write, against the specification's own grammar

**Status:** implemented, and it found something before it was installed.

## Context

Five instruments already answer for this package, and none of them asks the
question this one does.

| Instrument | Asks |
|---|---|
| qpdf | does the syntax close, do the offsets line up, do the streams decode |
| veraPDF | is this PDF/A, is this PDF/UA |
| pyHanko | does the CMS verify, was the certification honoured |
| `pdfsig` | is there a signature, does it cover the file |
| Ghostscript | produces baselines |

**Nothing asked whether an object is the object ISO 32000 describes.**
`Signing\Incremental\RevisionWriter` assembles the signature dictionary, the
widget, `/AcroForm`, `/DSS` and `/Perms` by concatenating strings. A wrong type,
a key that does not belong, or a value introduced in a later version than the
file declares would pass every check above.

## Decision

**The Arlington PDF Model is an instrument here**, on the terms in
[0026](0026-verification-tools-are-instruments.md): development and CI only,
`src/` may not name it, pinned, and it may not skip.

It is the PDF Association's machine-readable ISO 32000, 3465 TSV files
describing every dictionary, key, type, required-ness and introducing version,
plus `TestGrammar`, which checks a document against it. Apache-2.0.

### It was blocked, and this is what unblocked it

The issue that proposed it was held for three weeks because the build failed on
the image this project uses. Both causes are in code the project vendors rather
than writes, and both patches are applied at build time rather than carried as
files:

- `sarge.h` uses `uint32_t` without including `<cstdint>`. glibc pulls it in
  transitively and musl does not, so `-include cstdint` supplies it.
- pdfium's `logging.h` does `reinterpret_cast<volatile char*>(NULL)`, which
  GCC 15 rejects outright and `-fpermissive` does not downgrade. The line is
  dead code after `abort()`.

**Pinned by commit, not by tag.** The releases carry no binaries, and the TSV
model lives in the same tree, so one SHA pins the tool and the grammar together.

**The pdfium backend.** The alternatives are PDFix, a proprietary
`libpdfix.so`, and QPDF, which the project's own README marks as not working.

### The counts are asserted per file

Its traversal reaches the signature dictionary through `/Perms/DocMDP` and not
through the widget's `/V`: the same `/SubFilter` is reported on
`certified.pdf` and on nothing else, and forcing `pades-b-b.pdf` to the same
version still reports nothing.

**So a zero from this tool means "nothing found on the paths it walked", not
"the file is clean"**, and a single global assertion of zero would claim more
than the tool delivers.

## What it found

```
Error: wrong value for possible values: SubFilter (Signature) should be:
name [… fn:Extension(ETSI_PAdES,ETSI.CAdES.detached),
      fn:SinceVersion(2.0,ETSI.CAdES.detached)]
in PDF 1.7 and is name==ETSI.CAdES.detached
```

`/SubFilter /ETSI.CAdES.detached` became standard in **PDF 2.0**. Below that it
is the ETSI_PAdES developer extension, which ISO 32000-1 §7.12 says a file
declares in the catalog's `/Extensions`. The samples carry a `%PDF-1.4` header
and no `/Extensions` at all.

Isolated in both directions: forcing the version to 2.0 clears it, and enabling
the extension clears it.

**It is spec hygiene rather than breakage**: poppler, pyHanko and veraPDF accept
these files, and so does every reader in practice.

*It is fixed.* The catalog now declares the extension, under the ESIC prefix as
ISO 32000-1 §7.12 requires, rather than raising `/Version` to 2.0: raising the
version asserts the whole document is PDF 2.0, which is a claim about bytes this
package only appended to, and the same reasoning that stopped 0025 inventing an
`/ID`.

**Measured while fixing it, and worth knowing:** TestGrammar's `--extensions`
flag tells the *model* which definitions to load and does **not** read the
file's own `/Extensions`. So declaring it does not change the tool's verdict.
The signed samples are therefore checked with `ETSI_PAdES` enabled, which
describes what they are, and one test still runs without it and asserts the
complaint, so the day the sub-filter or the version changes that stops being
true loudly.

That finding is what earns the instrument its place. [0026](0026-verification-tools-are-instruments.md)
says each one has done so by finding something the suite passed, and this one
did it in the first five minutes.

## Consequences

- The development image gains a compile: `build-base` and `cmake` are installed
  and removed in the same layer, and the build takes about four minutes. **It is
  by some distance the most expensive instrument here to install**, and that is
  the price of the only question nobody else answers.
- `tests/Conformance/ArlingtonTest.php` blocks, in the `arlington` group.
- `tests/Project/ArchTest.php` gains `testgrammar` and `arlington` in its list
  of banned literals.

## Alternatives rejected

| | Why not |
|---|---|
| The PDFix backend | A proprietary shared library in an otherwise open toolchain |
| The QPDF backend | Marked not working by the project's own README |
| A separate compose service | The group would then skip by default, which 0025 reversed for veraPDF and for the same reason |
| Asserting one global zero | Claims coverage the traversal does not have |
| Fixing `/SubFilter` in the same change | Measure first. It changes the bytes of every signed document |
