# 0010: Validation consumes the material signing writes

**Status:** accepted, implemented. All three pieces are built. Trust remains out
of scope, as stated below.

## Context

The package writes long-term validation material and then ignores it.

`Signing\Incremental\DssWriter` embeds the certificate chain, OCSP responses and
CRLs into a Document Security Store for B-LT and above.
`Signing\Incremental\DocTimeStampWriter` closes B-LTA with an archive timestamp
over the whole file. Both are verified against poppler.

`src/Validation/` contains **no reference to the DSS at all**: not `/Certs`, not
`/OCSPs`, not `/CRLs`. And archive timestamps are classified but never checked.
From `PdfSignatureValidator`:

```php
verified: $signature['isTimestamp'] ? false : $this->verifier->verify(...)
```

A DocTimeStamp is reported with `verified = false` by construction, because
nothing verifies it.

**So the package produces B-LTA documents that it cannot itself validate as
B-LTA.** Sign with LTV, validate with this package, and you learn nothing about
the LTV. That asymmetry is the reason to do this: it is not new surface, it is
the missing half of surface that already exists.

## Decision, proposed

Three pieces, each independently useful and worth landing separately.

**Verify the timestamp token. Done.** An RFC 3161 token signs a TSTInfo carrying
the imprint of the covered bytes, so two things have to hold: the token's own
CMS verifies, and that TSTInfo's imprint is the digest of the range the token
covers.

**Checking only the first would be worse than checking neither**, because a
token lifted from a different document passes it: its CMS is perfectly valid,
it simply stamps other bytes. The test that matters is therefore the negative
one, and it is written: the same token, offered the wrong bytes, is refused.

The imprint is found rather than parsed. Walking the ASN.1 to the messageImprint
field would be more literal, but the value searched for is a digest computed
here, so a match cannot be coincidental. Finding it means the authority stamped
these bytes.

A timestamp is not detached, unlike a signature, which is why validation needs
two paths rather than one with a flag.

**Read the DSS. Done.** `Data\SecurityStore` reports how many certificates,
OCSP responses and CRLs the store holds, and which signatures it names.

Naming matters more than counting. `/VRI` keys entries by the SHA-1 of a
signature's `/Contents`, so a store can carry material for one signature in a
document that holds three. `SecurityStore::covers()` answers for a specific
signature, and `SignatureReport::hasLongTermMaterial()` answers for all of them
at once.

Two details the implementation had to get right, both verified by a test that
fails without them:

The dictionary is read by counting its own delimiters rather than to the first
`>>`, because `/VRI` nests and a naive reader cuts the store in half and reports
no certificates at all.

The **last** store is read, not the first, for the reason every other reader in
this package does the same (docs/spec/invariants.md).

An absent store and an empty one stay different answers: `null` for a B-B
document, a store of zeroes for one that carries the structure with nothing in
it.

The SHA-1 here is an identifier the PDF specification fixes, not a digest chosen
for security, which is why `tests/ArchTest.php` exempts one class from the weak
hashing rule rather than loosening it.

**Use it. Done.** `Validation\ChainBuilder` orders the certificates a signature
embeds into a chain, leaf first, and says whether it reaches a self-signed root.
No network is involved, which is the entire purpose of embedding the material.

**Each link is confirmed with the issuer's public key, not by matching names.**
Two certificates can carry identical subject and issuer names without one having
signed the other, so a name-matched chain looks right and proves nothing. The
test that carries the weight is therefore the one offering a second certificate
authority with the same subject name: it must not be chained.

This also fixed a quieter assumption. `SignatureDetails::signer()` returned
`signers[0]`, and a CMS carries its certificates as a **set**, in no guaranteed
order, so the first entry is not necessarily the leaf. It now prefers the
ordered chain and falls back to the old behaviour rather than to nothing.

A chain that stops short is not invalid: it means the material to finish it
lives outside the document, which is what a store exists to avoid.

## Not in scope

**Trust.** Whether the issuer is an authority you accept stays with the
consuming application, as it does today. Building a chain from embedded material
and checking it against a trust anchor are different steps, and this decision
covers the first. The second needs a `TrustStore` contract and a policy for
where roots come from, which is a decision of its own.

## Consequences

- `Data\SignatureDetails` gains fields, and it is a public return type, so this
  is a minor release.
- `Signing\Cades\HttpTransport` already exists as the audited network boundary,
  so revocation checking, if it is ever added, has a home and does not open a
  second SSRF surface.
- The samples become test material: `pades-b-lt.pdf` and `pades-b-lta.pdf` are
  already committed and already carry exactly the structures this reads.
