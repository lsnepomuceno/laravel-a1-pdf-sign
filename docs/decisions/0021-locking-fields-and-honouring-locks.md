# 0021: Locking fields, and honouring the locks already there

**Status:** implemented.

## Context

A template is signed in stages: the manager signs, then the employee, then
somebody fills in an amount. **Nothing stopped the amount changing after the
manager signed it.**

ISO 32000-1 §12.7.4.5 has the answer, and the package had neither half of it. A
signature field may carry a `/Lock` saying which form fields stop being fillable
once *that* field is signed, and §12.8.2.4 has the `/FieldMDP` transform that
makes a reader enforce it.

Two gaps, and the second is the one that produces broken documents:

- the package could not **write** a lock, so a signer had no way to say "this is
  final";
- it did not **read** one either, so signing into a field an earlier signature
  had locked went through happily, producing a document whose earlier signature
  every reader reports as broken. The caller found out from the reader.

## Decision

**Write both halves, and refuse to break someone else's lock.**

### The widget's `/Lock` and the signature's `/FieldMDP`, together

The `/Lock` on the widget is what a reader **shows**. The `/FieldMDP` transform
in the signature's `/Reference` is what it **enforces**.

A document carrying only the first says the fields are locked and lets them be
filled anyway, which is worse than saying nothing. So both are written or
neither is, the same rule certification follows for `/Perms` and `/DocMDP`
([0012](0012-certification-signatures.md)).

### One `/Reference` array, not two

A signature may certify the document **and** lock fields. Both transforms go in
one array, because two `/Reference` entries in one dictionary leave a reader to
pick one.

This also puts a FieldMDP transform next to a DocMDP one in exactly the
arrangement [0012](0012-certification-signatures.md) already guards against
misreading: `CertificationReader` checks the method, so a field lock is not
reported as a document certification.

### An empty include or exclude is refused

`/Include` with no fields locks nothing. `/Exclude` with no fields locks **every
field there is**. Neither is plausibly what the caller meant, and the second is
by far the more expensive to discover, so both raise `FieldLockException` before
anything is written.

### A lock on an unsigned field is not in force

A `/Lock` on a field nobody has signed states what will happen *when* it is
signed. Reading it as already in force would make a template that ships such a
field unsignable, which is the opposite of the point.

Nor can a lock lock the field that imposed it: that signature has already filled
its own field, so reporting a conflict there would describe one that cannot
arise.

### Refusing, not warning

Filling a locked field is refused. The alternative is a valid-looking document
whose earlier signature is broken, and this package has taken the same position
on every equivalent question: a missing field ([0013](0013-signing-into-an-existing-field.md)),
a document certified at no-changes ([0012](0012-certification-signatures.md)),
an encrypted document ([0014](0014-refuse-encrypted-documents.md)) and a seal
placed on a page that does not exist ([0017](0017-the-seal-goes-where-it-was-asked-for.md)).

## Consequences

- `Data\FieldLock` and `Enums\FieldLockAction` are public. `FieldLock::all()`,
  `::only([...])` and `::except([...])` are the constructors worth using.
- `PendingSignature::lock()` defaults to locking everything, since a caller
  reaching for it usually means "final".
- `Contracts\PdfSigner::sign()` gains a trailing optional `?FieldLock`, and can
  now raise `FieldLockException`.
- `IncrementalSigner` takes `FieldLockReader` as a further defaulted
  constructor parameter, **appended**, so the arity a hand-built signer relies
  on does not move. That break is the one 2.2.0 shipped and 2.2.1 undid.
- Field names are escaped when written. A name containing `)` would otherwise
  end the `/Fields` array early and leave the rest of it as syntax.

## Alternatives rejected

| | Why not |
|---|---|
| Write `/Lock` without the `/FieldMDP` transform | The document says locked and behaves unlocked |
| Warn and sign anyway | A broken signature the caller learns about from Adobe |
| Treat a `/Lock` on an unsigned field as in force | Makes a template that ships one unsignable |
| Let `/Exclude` with no fields mean "lock everything" | It is the same as `/All`, written in the way most likely to be a mistake |
| Enforce the lock at validation time instead | Validation reports what a document is; this is about not producing a broken one |
