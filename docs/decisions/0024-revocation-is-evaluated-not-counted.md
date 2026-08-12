# 0024: Revocation is evaluated, not counted

**Status:** implemented.

## Context

The Document Security Store has been written since 2.0 and, since
[0010](0010-validation-consumes-what-signing-writes.md), **counted**:
`$report->securityStore->ocspResponses` said how many responses were in there,
and nothing said what any of them meant.

So a document could carry a responder's signed statement that its signer's
certificate had been **revoked**, and this package would report it as valid,
with a `SecurityStore` cheerfully announcing that one OCSP response was present.

That is the worst direction for this particular gap to run in. The material
exists precisely so a verifier can answer the question after the responder is
gone, and B-LT's entire purpose is to carry it.

## Decision

**Read the material, verify it, and answer with it.**

`Validation\RevocationReader` resolves the `/OCSPs` and `/CRLs` references into
DER; `Validation\RevocationChecker` decides what they say about a serial.
`Data\SignatureDetails::$revocation` reports it, as `Enums\RevocationStatus`.

### Nothing is believed on sight

A response or a CRL is evidence only if it verifies against the issuer that
signed it, so each one's signature is checked with `openssl_verify()` before its
contents are read at all. Material that does not verify is material that is not
there.

**This caught a real hole during development.** The first version collected the
responder certificate from inside the response and used it as a verification
key, because RFC 6960 allows a delegated responder and the response carries it.
A test that offered an unrelated issuer still got `Revoked` back: the response
was vouching for itself, which is exactly what a forged one does.

An embedded responder is now accepted only when one of the supplied issuers
actually issued it (`openssl_x509_verify`), per §4.2.2.2.

### Three answers, not two

| | |
|---|---|
| `Good` | verified material covers this certificate and does not revoke it |
| `Revoked` | verified material says it was revoked |
| `Unknown` | nothing carried, nothing matching this serial, or nothing that verifies |

"Nothing in this document says" is not "this certificate is fine", which is the
same distinction [0016](0016-trust-is-the-applications-policy.md) drew for
trust and [0011](0011-signing-time-and-certificate-validity.md) for signing time.

**One verified revocation outweighs any number of good answers.** A responder
saying a certificate is revoked is not something a second opinion undoes.

### A CRL that lists nothing is an answer

An empty or non-matching CRL reports `Good`, not `Unknown`. A certificate
revocation list is a positive statement about what is revoked, so a verified one
that does not name this serial has said something.

### Revocation does not decide `isValid()`

`isValid()` still answers "does this signature match these bytes", and a revoked
certificate produces a signature that matches perfectly. What it stops being is
a signature anyone should accept, which is a policy question and stays with the
application, beside trust.

### Parsed here, verified by OpenSSL

The structures are walked with `Validation\Asn1Reader`
([0019](0019-validation-reads-what-it-writes.md)) and the cryptography is
`openssl_verify()` and `openssl_x509_verify()`, both `ext-openssl`. No new
shell-out: invariant 8 keeps `Support\ProcessRunner` the only place a process is
spawned, and nothing here needs one.

The lists are located by the shape of their entries rather than by position,
because both structures put them among optional fields: a CRL with no version
and no `nextUpdate` shifts every index.

## Verification

The fixtures under `tests/Resources/revocation/` are produced by **OpenSSL
itself**, not by this package: a CA, a leaf with serial `0x1234`, and a good and
a revoked answer of each kind. `openssl ocsp -respin ocsp-revoked.der` reports
`Response verify OK` and `leaf.pem: revoked`, and `openssl crl` lists serial
`1234` in the revoked list.

Checking against material this package generated would only establish that the
reader agrees with the writer, which is the same reason
[0019](0019-validation-reads-what-it-writes.md) tests against freetsa.org tokens
and [0020](0020-decode-the-filters-documents-use.md) against the LZW example in
the standard.

## Consequences

- `Data\SignatureDetails` gains `$revocation` and `isRevoked()`, so `toArray()`
  changes shape again.
- `PdfSignatureValidator` takes two more trailing defaulted constructor
  parameters, appended.
- **Nothing this package signs will report anything but `Unknown` yet.** The
  debug certificate is self-signed with no responder and no distribution point,
  so `DssWriter` has no material to collect and the store carries only the
  chain. The path is exercised against real OpenSSL fixtures instead, and a
  document from a real authority is what would exercise it end to end.
- The issuers offered to the checker are the rest of the chain the signature
  embeds. A response signed by an authority the document does not carry is
  therefore `Unknown`, which is correct: it is unverifiable here.

## Alternatives rejected

| | Why not |
|---|---|
| Trust the responder certificate the response embeds | The response vouches for itself, and so does every forgery |
| Let `Revoked` make `isValid()` false | Two different questions. The signature does match the bytes |
| Report `Good` when nothing was carried | The absence of evidence, reported as evidence |
| Report `Unknown` for a CRL that lists nothing | A verified CRL that omits a serial has said something about it |
| Shell out to `openssl ocsp -respin` | `openssl_verify()` is in `ext-openssl` and needs no process |
| Locate the lists by field position | Both structures put them after optional fields |
