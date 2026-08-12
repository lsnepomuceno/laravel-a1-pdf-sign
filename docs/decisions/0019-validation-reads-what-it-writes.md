# 0019: Validation reads what it writes, one level down

**Status:** implemented.

## Context

[0010](0010-validation-consumes-what-signing-writes.md) closed the gap between
what the signer wrote and what the validator read, and it closed it at the
document level: the Document Security Store is read, and the archive timestamp
of B-LTA is verified rather than merely noticed.

**It left the same gap one level down, inside the CMS.**

A `pades-b-t` signature carries an RFC 3161 token as an unsigned attribute of
its SignerInfo. The package has embedded one since 2.0 and never once looked at
it. So a B-T document reported `isValid() === true` with **nobody having checked
the single thing B-T adds over B-B**: a third party's word on when the signature
existed. The DocTimeStamp of B-LTA was verified; the signature timestamp of B-T,
B-LT and B-LTA was not.

The report could not answer a second question either. It knew a signature
verified and it knew the document had a store, but not **what profile the
signature was made at**. `/SubFilter` was read to locate the signature and
thrown away.

## Decision

**Verify the token, and report the level the document actually reaches.**

### A real ASN.1 walk, not a byte search

`Validation\DerReader` answers how long the structure at an offset is, which is
all the placeholder-trimming it exists for needs. Reaching the signature value
and the unsigned attributes needs to know **which child is which, in order**.

`Validation\Asn1Reader` and `Asn1Node` do that: definite lengths, single-byte
tags, both restrictions being DER's own. Anything outside them reads as null
rather than as a guess, and a parent whose children do not fit it yields nothing
at all, because half a walk is worse than none: a caller indexes into the list
and gets a confident answer about the wrong field.

Searching the CMS for the attribute's OID bytes would have been shorter and is
wrong. **That byte sequence occurs inside certificates the CMS embeds.** The OID
is compared as dotted text, only after the walk has arrived at a node that is an
OBJECT IDENTIFIER in the attribute-type position.

### The token stamps the signature, not the document

RFC 3161 §2.4.1, by way of CAdES: the imprint is the digest of the SignerInfo's
`signature` OCTET STRING and of nothing else.

A verifier handed the document's bytes instead would fail on every correctly
built file, and the natural fix for that failure, dropping the imprint check and
keeping only "the token's CMS verifies", is worse than not checking at all: a
token lifted from an unrelated document passes it.

So both halves hold or the answer is false, which is the same rule 0010 applied
to the archive timestamp.

### Null is not false

A signature with no token reports `timestampVerified === null`. It is not a
signature with a broken token; there is nothing to check. Collapsing the two
would report every baseline signature as carrying a failure, which is the
distinction [0011](0011-signing-time-and-certificate-validity.md) drew for an
absent signing time and [0016](0016-trust-is-the-applications-policy.md) drew
for trust.

### The profile is read from the document, not from its claim

`SignatureProfile::classify()` builds the answer from what is actually there: a
verified token lifts B-B to B-T, a security store lifts that to B-LT, and a
DocTimeStamp lifts that to B-LTA. The levels are cumulative, so a store with no
verified timestamp is **not** B-LT, it is B-B with a store.

A `/SubFilter` says the signature is CAdES. It does not say a token is present,
and a document produced at B-LTA by one tool and stripped by another still says
CAdES. Both are reported: `$signature->subFilter` is the claim,
`$signature->profile` is the reading, and a caller comparing them can see a file
that says one thing and carries another.

### Measured against real tokens

The tests run against the committed samples rather than against fixtures
generated offline, because those carry tokens from freetsa.org. A fixture this
package generates would only establish that the reader agrees with the writer.

| Sample | `subFilter` | `profile` | token | genTime |
|---|---|---|---|---|
| `legacy.pdf` | `adbe.pkcs7.detached` | legacy | none | |
| `pades-b-b.pdf` | `ETSI.CAdES.detached` | pades-b-b | **none** | |
| `pades-b-t.pdf` | `ETSI.CAdES.detached` | pades-b-t | **verified** | 2026-08-06 03:46:51Z |
| `pades-b-lt.pdf` | `ETSI.CAdES.detached` | pades-b-lt | **verified** | 2026-08-06 03:46:52Z |
| `pades-b-lta.pdf` | `ETSI.CAdES.detached` | pades-b-lta | **verified** | 2026-08-06 03:46:53Z |
| `pades-b-lta.pdf`, entry 2 | `ETSI.RFC3161` | none | it *is* the token | |

## Consequences

- `Data\SignatureDetails` gains four properties: `timestampVerified`,
  `stampedAt`, `subFilter` and `profile`, which changes the shape `toArray()`
  returns. It gains `hasTimestamp()` and `attestedAt()`.

- **`attestedAt()` deliberately does not fall back to `signedAt`.** One is the
  authority's clock and the other is the signer's, and a caller reading an
  unattested time as an attested one is the whole reason the distinction exists.

- `Validation\PdfSignatureValidator` takes `TimestampTokenReader` as a trailing
  optional parameter, appended after `$trust` so a positional caller keeps
  meaning what they meant.

- Verifying a token costs two `openssl` invocations per signature that has one,
  through the audited runner. A B-B document pays nothing.

- `Enums\Asn1Tag` and `Enums\CmsAttribute` are the first enums written under
  [0018](0018-prefer-the-platforms-own-constructs.md). `Asn1Tag` is int-backed
  and exempted by name from the string-backed arch rule.

## Alternatives rejected

| | Why not |
|---|---|
| Search the CMS for the attribute's OID bytes | The sequence occurs inside embedded certificates. It would find one and report on it |
| Accept a token whose CMS verifies, without the imprint | A token lifted from another document passes. Worse than not checking |
| Report `timestampVerified` as false when there is no token | An absence reported as a failure, for every B-B signature ever made |
| Take the profile from `/SubFilter` alone | It says CAdES for B-B through B-LTA alike, and keeps saying it after the material is stripped |
| Fall back to `signedAt` in `attestedAt()` | Hands the signer's own clock to a caller who asked what a third party attested |
