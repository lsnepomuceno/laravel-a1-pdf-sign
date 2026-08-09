# 0012: Certification signatures and DocMDP

**Status:** proposed. Requested in
[discussion #160](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/160).

## Context

Every signature the package writes is an **approval** signature. There is no
`/DocMDP` transform and no `/Perms` entry anywhere in `src/`, so nothing tells a
reader to restrict what may happen to the document afterwards.

What the package offers instead is detection: each signature covers the file as
it stood at its own revision, so a later change is visible as "valid, with
subsequent changes". That is a different guarantee from locking, and for many
workflows it is the one that matters. It is not what was asked for.

## Decision, proposed

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

## Verification

The suite cannot answer this one on its own. Whether a reader honours a
certification is precisely what varies between readers, so this needs poppler
plus at least one of Adobe Reader and ITI Validar, on a document certified at
each level and then modified. A test asserting the bytes were written is
necessary and nowhere near sufficient.

## Consequences

- `Signing\PendingSignature` gains an entry point. The v2 plan proposed
  `certify(CertLevel)` and it was never built, so the name is available and the
  original intent is on record.
- Multi-signature and certification become a documented either/or at level 1,
  which is a thing to explain in the docs rather than to leave for a support
  thread.
