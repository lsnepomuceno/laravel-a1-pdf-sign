# 0022: The archive timestamp is a chain, not a state

**Status:** implemented. The extension itself is verified only under the
`network` group; see *Verification* below.

## Context

The package could produce a PAdES B-LTA document and could not maintain one.

B-LTA is not a state a document stays in. An archive timestamp is worth exactly
as much as the authority's certificate and the digest algorithm behind it, and
both age. ETSI EN 319 142-1 answers that with a **chain**: before the current
timestamp stops being verifiable, a new one is stamped over everything,
including the previous timestamp, while that one still checks out.

Without the second link, a document signed for a twenty-year retention has to be
**re-signed** to stay checkable, and re-signing loses the original signing time,
which is the one thing the archive existed to preserve.

## Decision

**Expose extending as its own operation, and guard it like a signature.**

`Signing\ArchiveExtender` and `A1PdfSign::extendArchive()`.

### No certificate is involved

A DocTimeStamp is signed by the authority, not by the signer. Extending an
archive therefore needs no key material at all, which means a scheduled job can
walk a directory of archived documents and re-stamp them with nothing sensitive
anywhere near it. That is why this is not a method on `PendingSignature`, whose
whole shape starts from a certificate.

### The same refusals a signature gets

- **An unsigned document is refused.** Timestamping one is legal and pointless:
  it attests bytes nobody vouched for, and hands back a file that looks archived
  while proving nothing about a signer.
- **A document certified at no-changes is refused**, because an archive
  timestamp is a further revision and that is exactly what `/P 1` forbids
  ([0012](0012-certification-signatures.md)).

### A document with no timestamp yet is not refused

`isArchived()` reports; it does not gate. Extending a B-T document makes it
archived from that point on, and the chain has to start somewhere. Refusing
would mean the only way to reach B-LTA was to have asked for it at signing time.

### The field index comes from the form, not from a byte scan

`DocTimeStampWriter` named its widget by counting `/FT /Sig` occurrences in the
raw bytes. That count is wrong in a document whose fields are packed into an
object stream, which [0015](0015-object-streams.md) made signable in 2.3, and
the consequence is two fields sharing a name, which is a form readers disagree
about.

It now counts what `/AcroForm /Fields` declares, which is the authoritative list
and the one `SignatureFieldReader` already walks. This was a latent defect
rather than something extending caused; extending is what made it reachable
twice in one document.

## Verification

The guards run offline. **The extension itself needs a timestamp authority**, so
it sits in the `network` group with every other test that reaches one, and it is
not exercised by `composer check` on a machine without internet.

What the network tests assert:

- the previous links survive **byte for byte**, which is what lets the new
  timestamp attest them;
- the document then carries two entries in `timestamps()` and one signature, all
  three verifying;
- the signature underneath still reads as `pades-b-lta` with its own signature
  timestamp verified ([0019](0019-validation-reads-what-it-writes.md)).

## Consequences

- `Contracts\A1PdfSign` gains `extendArchive()`. That is a break for anyone
  implementing the contract, which the Roave check reports.
- `DocTimeStampWriter` takes `SignatureFieldReader` as a further defaulted
  constructor parameter, appended.
- ~~Nothing refreshes the Document Security Store while extending.~~ **Fixed.**
  This section said a rigorous chain re-collects revocation material for the
  previous timestamp's certificate before stamping over it, and that this did
  not: it appended the timestamp only. A document could therefore gain a fifth
  archive timestamp over evidence gathered on the day it was signed, which is
  the one thing long-term validation exists to prevent.

  `extend()` now refreshes the store first, and the order is the correctness
  claim: ETSI EN 319 142-1 puts the material for everything the document already
  carries **inside the file while it is still verifiable**, and the archive
  timestamp then covers it. A store appended afterwards is material the
  timestamp does not attest.

  The chains are built per signature with `Validation\ChainBuilder` rather than
  pooled, because `collectValidationMaterial()` treats each certificate's
  neighbour as its issuer: a mixed pile would build an OCSP request against the
  wrong pair and embed the answer. The timestamps' own chains are included, and
  that is the point of the exercise, since the authority's certificate is what
  the *next* archive timestamp has to be able to check.

  Gated offline by `tests/Timestamps/ArchiveRefreshTest.php`. Two pieces of test-only
  machinery were needed and neither is a stub of the thing under test:
  `DebugCertificate::makeRevocable()` issues a certificate that names its own
  distribution point, because `collectValidationMaterial()` reads the endpoints
  from the certificate and one carrying none is never asked about whatever
  transport is bound; and `Testing\LocalRevocationAuthority` answers with
  material, where `LocalTimestampAuthority` answers false. Whether that material
  is *good* is [0024](0024-revocation-is-evaluated-not-counted.md)'s question,
  with its own fixtures.

## Alternatives rejected

| | Why not |
|---|---|
| A method on `PendingSignature` | Its shape starts from a certificate, and this needs none |
| Refuse a document that is not already B-LTA | The chain has to start somewhere, and that makes signing time the only chance to reach it |
| Re-sign instead of re-stamping | Loses the original signing time, which is what the archive was preserving |
| Keep counting `/FT /Sig` in the bytes | Undercounts a packed form, and two fields share a name |
