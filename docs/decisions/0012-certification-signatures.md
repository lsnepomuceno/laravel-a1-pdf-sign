# 0012: Certification signatures and DocMDP

**Status:** implemented, with one part of the verification outstanding and
named below. Requested in
[discussion #160](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/160).

## Context

Every signature the package writes is an **approval** signature. There is no
`/DocMDP` transform and no `/Perms` entry anywhere in `src/`, so nothing tells a
reader to restrict what may happen to the document afterwards.

What the package offers instead is detection: each signature covers the file as
it stood at its own revision, so a later change is visible as "valid, with
subsequent changes". That is a different guarantee from locking, and for many
workflows it is the one that matters. It is not what was asked for.

## Decision

Add certification through `/DocMDP`, at the three levels ISO 32000-1 §12.8.2.2
defines:

| Level | Permits |
|---|---|
| 1 | nothing; any change invalidates |
| 2 | form filling and signing |
| 3 | level 2 plus annotations |

**Level 1 is in direct tension with the package's most important behaviour.** A
certification at level 1 forbids the later revisions that additional signatures
require, so a document certified at level 1 cannot be signed again. That is the
standard's intent, not a defect, but it means the API has to make the exclusion
obvious rather than let a caller discover it when the second signature silently
invalidates the first.

Constraints the implementation must enforce, not merely document:

- **At most one certification per document**, and it must be the first
  signature. A second one, or one applied after an approval signature, is an
  error rather than a warning.
- **Level 1 refuses to sign a document that already carries a signature**, for
  the same reason.
- The `/Perms` entry has to agree with the `/DocMDP` transform. A mismatch is
  the kind of thing readers disagree about, so both are written together or
  neither is.

## What was built

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->certify('form-filling')   // or a CertificationLevel
    ->sign();

$report = A1PdfSign::validate($path);
$report->isCertified();               // true
$report->certification;               // CertificationLevel::FormFilling
$report->acceptsFurtherSignatures();  // false only at no-changes
```

`Enums\CertificationLevel` names the permission rather than the number.
`/P 1` says nothing at all is allowed, which is a statement about the document
rather than a level of anything, and a configuration file reading `no-changes`
needs no table beside it.

`RevisionWriter` writes both halves together: `/Reference` carrying the DocMDP
transform on the signature dictionary, and `/Perms<</DocMDP N 0 R>>` on the
catalog naming that signature. `/V` in the transform parameters is fixed at
`1.2`, the version of the parameter dictionary itself, unrelated to the PDF
version or to the profile.

**`certify()` defaults to `form-filling`, not to `no-changes`.** A document that
still has to be signed is the common case, and defaulting to the level that
refuses the next signer would make the feature fail closed in the wrong
direction. `CertificationLevel::resolve()` does the opposite for an unreadable
string: it falls back to `no-changes`, because a value nobody can parse must not
quietly become the most permissive one.

### The three rules, enforced

| | |
|---|---|
| A certification must be the first signature | it states what may happen from here on, and an approval signature already applied is a thing that happened |
| One certification per document | a second is refused, not merged |
| `no-changes` refuses every later signature | a further signature is a further revision, which is exactly what `/P 1` forbids |

The third applies to approval signatures too, not only to a second
certification. That is the whole point: the exclusion has to be obvious rather
than something a caller discovers when the second signature silently invalidates
the first.

### Validation reads it back

`SignatureReport` gained `certification`, `isCertified()` and
`acceptsFurtherSignatures()`. Writing a certification the package could not then
report would repeat the asymmetry
[0010](0010-validation-consumes-what-signing-writes.md) exists to close.

**Half a certification is not a certification.** `CertificationReader` requires
`/Perms` and the transform to agree: a `/Perms` naming a signature with no
DocMDP transform, a transform with a `/P` the standard does not define, or a
`/FieldMDP` transform (§12.8.2.4, which locks named fields and carries the same
`/P`) all report null. Reading the parameters without checking the method would
report a field lock as a document certification.

## Verification

The suite cannot answer this one on its own. Whether a reader honours a
certification is precisely what varies between readers.

**What was verified.** All three levels write, `CertificationReader` reads each
back, and poppler reports the signature as valid in every case, so the
`/Reference` array does not disturb the CMS or the byte range. The structure was
checked directly:

```
PERMS: /Perms<</DocMDP 19 0 R>>
REF:   /Reference[<</Type/SigRef/TransformMethod/DocMDP/TransformParams<</Type/TransformParams/P 2/V/1.2>>>>]
SIGOBJ: 19
```

The `/Perms` entry names object 19, which is the signature dictionary carrying
the transform. `poc/certify.php` produces these and exercises the exclusion:
signing a `no-changes` document is refused, and signing a `form-filling` one
succeeds with the certification intact.

**What was not verified, and this is the gap.** `pdfsig` does not surface
`/DocMDP` at all, so poppler cannot tell us whether the certification would be
*enforced*, only that the file is well formed and the signature verifies. That
needs Adobe Reader or ITI Validar on a document certified at each level and then
modified, which is not runnable here. **A test asserting the bytes were written
is necessary and nowhere near sufficient**, exactly as this record said before
the work started, and saying so afterwards costs nothing while claiming
otherwise would cost a user.

`samples/certified.pdf` exists for that check: certified at `form-filling` and
then signed by a second party, so a reader can be asked both whether it reports
the certification and whether it accepts the approval signature that followed.

## Consequences

- `Signing\PendingSignature` gained `certify()`. The v2 plan proposed the name
  and it was never built, so the original intent is on record and the name was
  free.
- Multi-signature and certification are a documented either/or **at
  `no-changes` only**. Levels 2 and 3 exist precisely so the document can still
  be signed, and the refusal is scoped to the level that means it.
- `Contracts\PdfSigner::sign()` gained a trailing optional parameter and
  `Data\SignatureReport` gained a property, which changes the shape
  `toArray()` returns. `tests/DataTest.php` failed on that and had to be
  updated deliberately, which is the gate working.
