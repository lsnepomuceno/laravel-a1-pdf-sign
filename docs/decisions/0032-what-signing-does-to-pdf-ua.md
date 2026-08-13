# 0032: What signing does to PDF/UA, measured

**Status:** implemented. The measurement came first and the fix followed it,
separately, which is the part worth copying.

## Context

[0025](0025-what-signing-does-to-pdf-a.md) measured what signing does to PDF/A
and made the answer a gate. PDF/UA had never been asked, and the reason to ask
was not curiosity: an accessible document that stops being accessible because
somebody signed it is a real harm to a real reader, and nothing here would have
noticed.

The rule 0025 set applies unchanged:

> That is not a gap that reasoning closes. Either a validator says yes or nobody
> knows.

The instrument was already installed. veraPDF 1.30.2, pinned, in the
development image and in CI, carries a `ua1` validation profile alongside the
PDF/A ones. So this cost a baseline and a test file, not an adoption.

## Measurement

`tests/Resources/pdfua-1.pdf`, produced by LibreOffice Writer 7.4 from the
`.fodt` committed beside it, confirmed conformant by veraPDF before anything
was done to it. **Ghostscript cannot produce this baseline**, which is why the
source is committed with it: PDF/UA needs a tagged structure tree the source
document has to carry, and a converter does not synthesise one.

| | veraPDF `ua1` | Failing clauses |
|---|---|---|
| **Baseline, unsigned** | PASS | none |
| **Invisible signature** | **PASS** | none |
| Opaque seal | FAIL | 7.18.1, 7.18.4 |
| Transparent seal | FAIL | 7.18.1, 7.18.4 |

Before `/Tabs` was written, which is the state this record was first published
in, the invisible signature failed on 7.18.3 and each seal failed on all three:

| | veraPDF `ua1` | Failing clauses |
|---|---|---|
| **Invisible signature** | FAIL | 7.18.3 |
| Opaque seal | FAIL | 7.18.1, 7.18.3, 7.18.4 |
| Transparent seal | FAIL | 7.18.1, 7.18.3, 7.18.4 |

The clauses, from ISO 14289-1:

- **7.18.3** Every page carrying an annotation shall have `/Tabs` with the value
  `S` in its page dictionary.
- **7.18.1** A widget annotation shall be nested within a `Form` tag in the
  structure tree.
- **7.18.4** A form field shall carry `/TU`, or every one of its widgets shall
  carry an `/Alt`.

## What the measurement found

**An invisible signature failed on one clause, and it was the cheap one.**
`RevisionWriter` already rewrote the page object to add the widget to
`/Annots`, and did not add `/Tabs` while it was there. That is one key in a
dictionary this package was already writing, and it is now written (issue #265):
**an invisible signature keeps a PDF/UA document conformant.**

A page that already declares `/Tabs` is left alone, whatever it declares. `/S`
is what accessibility asks for, and `/R` and `/C` are legitimate choices a
producer makes about their own document; overwriting one would be the signer
deciding how somebody else's page is navigated. A document arriving as PDF/UA
already carries `/S`.

**A seal fails on three, and the other two are not cheap.** Nesting a widget
inside a `Form` tag means writing into the structure tree: finding
`/StructTreeRoot`, appending an element, maintaining `/ParentTree` and
`/StructParents`. There is no occurrence of `StructParent` or `StructTreeRoot`
anywhere in `src/` today.

**Transparency changes nothing**, which is the opposite of PDF/A. Part 1 of
PDF/A forbids `/SMask` outright and no arrangement makes a transparent seal
conformant to it ([0023](0023-a-seal-that-can-be-transparent.md)). PDF/UA has
no rule against transparency, so the opaque and transparent seals fail
identically.

**A document that was never accessible loses nothing.** `tests/Resources/test.pdf`
carries no structure tree, so it fails `ua1` before and after signing. The claim
this record makes is bounded: signing costs PDF/UA conformance to documents that
had it.

### The fix was deliberately not part of the measurement

0025 was written the other way round, fixing `/ID` and the missing appearance
inside the measurement, and this record deliberately did not repeat that.
Measuring and fixing in one change makes it impossible to tell afterwards which
verdict came from which state, and the fix touches `src/Signing`, so it changes
the bytes of every signed document.

**The separation paid for itself immediately.** Because the tests named the
clauses rather than asserting "it fails", writing `/Tabs` broke
`tests/Conformance/PdfUaValidationTest.php` and forced it to be updated to a PASS. Had the
expectation been "this fails", the improvement would have gone unnoticed and
the file would have kept asserting something that had stopped being true.

The tests assert the failures **exactly**, clause by clause, rather than
asserting "it fails". A document one key away from conformant and a document
that needs a structure tree are different situations, and the day the first
becomes conformant is the day an assertion should fail and be updated rather
than quietly keep passing.

## Consequences

- `tests/Conformance/PdfUaValidationTest.php` blocks, in the `pdfua` group. veraPDF is
  deterministic and runs offline once installed, so a failure is this package's
  rather than somebody else's outage.
- **Nothing skips.** `composer test` carries `--fail-on-skipped`.
- The `.fodt` source of the baseline is committed. 0025 recorded "Ghostscript
  9.55" and no command, and regenerating a baseline from that is guesswork; the
  recipe here is in the test file's docblock and the input is in the repository.
- **PDF/UA is not claimed anywhere.** The README and `docs/spec/public-api.md`
  make a PDF/A claim and now say what is known about PDF/UA, which is that
  signing costs it.

## Alternatives rejected

| | Why not |
|---|---|
| Fix `/Tabs` inside this change | The same conflation 0025 made. Measure first |
| Assert "it fails" without the clauses | Says nothing about how far from conformant, and cannot notice an improvement |
| Produce the baseline with Ghostscript | It cannot. PDF/UA needs a structure tree from the source document |
| Skip when veraPDF is absent | How the PDF/A group went a release validating nothing |
| Claim PDF/UA is out of scope | It is out of scope only once somebody has checked. Nobody had |
