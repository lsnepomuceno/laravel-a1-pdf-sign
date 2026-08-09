# 0011: The report carries signing time and certificate validity

**Status:** accepted, implemented.

## Context

`Data\SignatureDetails` carried `verified`, `signers`, `coverageEnd`,
`coversWholeDocument`, `isTimestamp` and `error`. It answered "do these bytes
match this CMS" and nothing about *when*.

The question a legal or compliance reader actually asks is **"was the
certificate valid at the moment it signed?"**, and the report could not answer
it. Neither could it answer "when was this signed", which poppler prints from
the same document:

```
- Signing Time: Aug 09 2026 12:01:52
```

The data was in the document the whole time. It simply was not surfaced.

## Decision

`SignatureDetails` gains `signedAt`. `Data\Signer` already carried `validFrom`
and `validTo`, so only the time was missing. From the two, a derived answer:

```php
$signature->signedAt;                  // ?int, unix timestamp, null when absent
$signature->signerWasValidWhenSigned(); // ?bool, null when either date is unknown
```

**`null` rather than `false` when the answer is unknown.** A signature with no
recorded time is not a signature made outside the validity window, and
collapsing the two would report an absence as a violation. The caller decides
what to do with not-knowing.

### Where the time comes from, and a correction

This record first said the time would be read from the PKCS#9 signing-time
signed attribute, OID 1.2.840.113549.1.9.5. **That was wrong, and measuring said
so:** the attribute is absent from the CMS of a freshly signed document, because
`tc-lib-pdf-sign` does not emit it.

The time is read from `/M` in the signature dictionary instead, which is what
this package writes and what poppler reports as the signing time.

That location is better than it sounds. `/M` sits inside the byte range the
signature covers, so altering it breaks the signature, where an unsigned
attribute could be edited freely. It is still the signer's own clock.

One practical trap, recorded because it cost a debugging round: `/M` is written
*after* `/Contents`, whose placeholder is 16 KB of hex, so it is roughly 16 KB
past the `/ByteRange` rather than just ahead of it. The sibling that reads
`/SubFilter` looks backwards from the same offset and works; a reader for `/M`
that copies that shape finds nothing.

## Consequences

- `Data\SignatureDetails` is a public return type, so adding a property changes
  the public shape. Minor release. The parameter is optional and last, so
  positional construction by a consumer keeps working.
- **The signing time is not proof.** It is the signer's own clock unless the signature carries an RFC 3161 timestamp, which
  is exactly what `pades-b-t` and above add. The docblock says so, and
  `signerWasValidWhenSigned()` is documented as answering the question the
  claimed time implies rather than a proven one. Making it provable is
  [0010](0010-validation-consumes-what-signing-writes.md).
- No network access is added. Everything here is read from bytes already in the
  document.
