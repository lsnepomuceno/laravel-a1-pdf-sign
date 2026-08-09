# 0010: Validation consumes the material signing writes

**Status:** proposed.

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

**Verify the timestamp token.** An RFC 3161 token signs a TSTInfo carrying the
imprint of the covered bytes. Verifying it means checking the CMS as it already
does for signatures, and then checking that the imprint matches the digest of
the range the token covers. Today the second half is what is missing, and it is
what makes a timestamp meaningful rather than decorative. `SignatureDetails`
gains the token's time.

**Read the DSS.** Parse `/DSS` from the catalog and expose what it carries:
which certificates, how many OCSP responses, how many CRLs. Purely descriptive
at first, which is enough to answer "does this document carry what B-LT
promises" without deciding whether the material is good.

**Use it.** Once the store is readable, validation can build the chain from the
embedded certificates rather than the network, which is the entire purpose of
the DSS: a document that verified in 2026 still verifies in 2036 without
reaching a responder that no longer exists.

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
