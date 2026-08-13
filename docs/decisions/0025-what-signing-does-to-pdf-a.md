# 0025: What signing does to PDF/A, measured

**Status:** implemented, and the measurement is the point.

## Context

Long-term archives are the reason this package exists, and PDF/A is the format
they are kept in. Whether signing a PDF/A document leaves it conformant had
**never been checked, in either direction**. The package neither claimed it did
nor warned that it might not.

That is not a gap that reasoning closes. Either a validator says yes or nobody
knows.

## Measurement

Baselines produced with Ghostscript 9.55 from `tests/Resources/test.pdf`, each
confirmed conformant by **veraPDF 1.30.2** before anything was done to it, then
signed three ways and validated again.

| | PDF/A-1b | PDF/A-2b |
|---|---|---|
| **Baseline, unsigned** | PASS | PASS |
| **Invisible signature** | **PASS** | **PASS** |
| Opaque seal | FAIL, §6.2.3.3 | FAIL, §6.2.4.3 |
| Transparent seal | FAIL, §6.2.3.3 and §6.4 | FAIL, §6.2.4.3 and §6.2.10 |

Before the two fixes below, **every one of those six failed**, including the
invisible signature.

## What the measurement found, and what was fixed

### The revision dropped the file identifier

*ISO 19005-2 §6.1.3: the trailer dictionary shall contain the `/ID` keyword.*

`RevisionWriter::trailer()` wrote `/Size`, `/Root`, `/Info` and `/Prev`, and no
`/ID`. The cross-reference stream writer omitted it too.

This is a defect well beyond PDF/A. ISO 32000-1 §14.4 makes `/ID` the file's
identity, and a revision that drops it hands every reader a document that has
stopped identifying itself. **It is fixed**, and the pair is carried through
unchanged: the second string is meant to change when the file does, and
inventing one here would be inventing a digest no reader checks, while the first
has to stay put either way.

A document with no `/ID` of its own gets none invented for it. A file identifier
is the producer's, and a signer that made one up would be claiming an identity
for a document it only appended to.

### An invisible signature had no appearance

*ISO 19005-1 §6.9: every form field shall have an appearance dictionary
associated with the field's data.*

A signature with no seal is still a form field. It now gets a form XObject with
a `[0 0 0 0]` box, which draws nothing, which is what invisible means. **Fixed**,
and it is what turns PDF/A-1b from FAIL to PASS.

## What is not fixed, and why

### A visible seal costs conformance, in both parts

**Fixed by [0028](0028-the-seal-carries-its-own-colour-space.md), which
took the next step this section named. What follows is the state that record
started from, kept because it is why the fix took the shape it did.**

*§6.2.3.3 (PDF/A-1) and §6.2.4.3 (PDF/A-2): DeviceRGB may be used only if the
file has an OutputIntent with an RGB destination profile.*

The seal is embedded as `/DeviceRGB`. The fix is not the signer's to make: an
OutputIntent declares the intended output device for the whole document and
requires an embedded ICC profile, which is the author's decision about their own
file, not something to add on the way past.

**The seal could carry its own `/ICCBased` colour space instead**, which would
make it conformant whatever the document declares. That means vendoring an sRGB
ICC profile into an MIT package, whose licensing needs checking first, so it is
named here as the next step rather than done quietly.

*It was not vendored in the end.* `Support\SrgbProfile` builds the profile from
IEC 61966-2-1 and ICC.1:2001-04, so the licensing question never had to be
answered: there is no third party's binary to license.

### A transparent seal can never be PDF/A-1

*§6.4: an XObject dictionary shall not contain the `SMask` key.*

PDF/A-1 forbids transparency outright, and `/SMask` is how a transparent seal
carries its alpha ([0023](0023-a-seal-that-can-be-transparent.md)). No
arrangement of this package's output makes a transparent seal conformant to
PDF/A-1.

`seal.transparent => false` is the lever, and it is the whole reason that
setting exists rather than the behaviour being unconditional.

PDF/A-2 allows transparency, and fails instead on §6.2.10: a page carrying
transparency needs a `/Group` with a `/CS` when the file has no OutputIntent.
The signer could add that group, and it would not change the verdict while
§6.2.4.3 still fails, so it waits on the same ICC decision.

*It did wait, and then it was the only rule left.* 0028 writes the group, and
PDF/A-2 with a transparent seal now passes. Part 1 does not, and cannot.

## Consequences

- **An invisible signature keeps a PDF/A document conformant, in both parts
  measured.** That is now a supported claim rather than a hope, and it is the
  recommendation for a PDF/A workflow.
- A visible seal did not, and the reason was the colour space rather than the
  signature. **That is now fixed**
  ([0028](0028-the-seal-carries-its-own-colour-space.md)): the seal carries its
  own `/ICCBased` profile, built rather than vendored, and every cell measured
  here passes except PDF/A-1 with a transparent seal, which §6.4 forbids.
- `tests/Resources/pdfa-1b.pdf` and `pdfa-2b.pdf` are committed as the
  baselines.
- **The measurement is now a gate.** `tests/Conformance/PdfAValidationTest.php` runs
  veraPDF itself, in the `pdfa` group. It **blocks**: veraPDF is deterministic
  and runs offline once installed, so a failure is this package's rather than
  somebody else's outage, which is what separates it from the timestamp group.

  It was briefly behind a build argument, installed only by a dedicated compose
  service, so that the everyday image would not carry a JRE. That was the wrong
  trade: the group then skipped by default, and a suite that skips its PDF/A
  checks leaves the conformance claims unverified on the machine where the work
  is happening. veraPDF is installed everywhere the suite runs, and
  `composer test` carries `--fail-on-skipped` so a skip cannot come back
  quietly.

  The group asserts the failures too. A sealed document is not conformant, and
  asserting that is what will tell someone the day it changes.

  Pinned to 1.30.2 in both the Dockerfile and the workflow: a validator that
  changes its verdicts between builds cannot be the thing a gate is measured
  against.

- `tests/Conformance/PdfAConformanceTest.php` keeps checking the **structure each verdict
  turned on**: the identifier is carried, the invisible field has an appearance,
  the seal's colour space is its own, the `/SMask` appears only when transparency
  is asked for. Those run everywhere, including where no JRE exists.

- **veraPDF is an instrument, not a dependency**, and neither are `pdfsig`,
  `pdftoppm` and Ghostscript. Nothing in `src/` may invoke one: a package that
  shells out to a JVM to answer a runtime question would be a different package,
  and the consuming application would inherit an installation requirement nobody
  wrote down. *Enforced by* `tests/Project/ArchTest.php`, tokenised so the comments that
  explain the rule do not trip it.

  It is installed by the `pdfa` compose service alone, behind a build argument,
  so the day-to-day image does not carry a JRE for one group.
- **The archive timestamp's widget had the same fault, and now it is fixed.**
  This record originally said `DocTimeStampWriter` had not been given an
  appearance, because a B-LTA document was not part of the measurement and
  claiming the fix covered it would have been the thing this record exists to
  avoid.

  Not claiming it was right; leaving it was not. `samples/pades-b-lta.pdf`
  shows the fault outright, in a file committed months ago:

  ```
  28 0 obj
  <</Type/Annot/Subtype/Widget/FT/Sig/Rect[0 0 0 0]/T (Timestamp2)/V 27 0 R…
  ```

  No `/AP`, beside a signature widget in the same document that has one. So the
  claim "an invisible signature keeps a PDF/A document conformant" would have
  stopped holding at B-LTA, which is precisely the combination an archive
  wants: PDF/A plus long-term validation is the canonical twenty-year artefact.

  `DocTimeStampWriter` now writes the same empty appearance the signature
  widget gets.

- **That fix is verified only under the `network` group.** A B-LTA document
  cannot be produced without reaching a timestamp authority, so the conformance
  verdict for one is reported rather than blocking, on the same terms as every
  other test that needs a TSA. The alternative was leaving it unmeasured, which
  is how it got here.

## Alternatives rejected

| | Why not |
|---|---|
| Add an OutputIntent to the document while signing | It declares the output device for the whole file. That is the author's statement, not the signer's |
| Invent an `/ID` for a document that has none | Claiming an identity for a document this only appended to |
| Change the second `/ID` string on each revision | It would be a digest no reader checks |
| Reason about conformance instead of measuring | The invisible signature "obviously" preserved conformance, and it failed on `/ID` |
| Vendor an sRGB ICC profile now | Licensing into an MIT package, decided quietly inside a measurement commit. [0028](0028-the-seal-carries-its-own-colour-space.md) built one instead, so nothing was vendored at all |
| Claim the fixes cover B-LTA too | Not measured. The timestamp widget has no appearance either |
