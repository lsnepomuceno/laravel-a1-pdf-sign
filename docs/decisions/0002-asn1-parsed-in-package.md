# 0002: Parse the CMS in-package, by declared length

**Status:** accepted, implemented, and the decision changed during
implementation. Read the outcome.

## Context

There is no clean native replacement for reading a detached PDF signature:
`openssl_pkcs7_verify()` needs the signed message reassembled, which is fragile.
v1 sidestepped the problem entirely: it extracted the PKCS#7 blob with a
`ByteRange` regex, ran three `preg_replace` calls over the `openssl` text
output, and reported a document as validated when the parsed subject happened to
contain a `CN` or `OU` field. A tampered document still has those.

Two options were weighed:

1. **Keep the CLI**, encapsulated, but parse certificates with
   `openssl_x509_parse()` instead of regex. No new dependency, immediate
   robustness gain. Recommended for 2.0 at the time.
2. **Add `phpseclib/phpseclib ^3`** and decode the CMS through ASN.1, which removes
   the CLI entirely and opens the way to actually verifying the signature. New
   dependency, more work. Pencilled in for 2.1.

## Decision

Neither, as written. The package reads ASN.1 itself, in `Validation\DerReader` and
`Validation\Pkcs7Reader`, and verifies cryptographically, without taking
`phpseclib` as a dependency.

`Validation\SignatureVerifier` remains a deliberate shell-out: verifying the CMS
against the covered bytes is the one operation where reimplementing was not
worth it.

## Consequences

- `SignatureReport::isValid()` means the CMS actually verifies against the bytes
  each signature covers. It is not a metadata check.
- **Every structure is read by its declared length.** Trimming trailing `0`
  bytes cuts legitimate DER. See [the invariants](../spec/invariants.md).
- DocTimeStamps are classified separately (`isTimestamp`) and excluded from
  `isValid()`: they are timestamps over the file, not signatures by a signer.
- All signatures in a document are reported, not only the first.

## Outcome

The honest note the plan carried, "the current method does not verify the
signature cryptographically, and the v2 naming must reflect that limitation
until option (ii) exists", is obsolete. Verification landed in 2.0, ahead of
the schedule the plan set, and without the dependency the plan expected to pay
for it.
