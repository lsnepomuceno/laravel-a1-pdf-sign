# 0016: Trust is the application's policy, and its verification is ours

**Status:** implemented.

## Context

`isValid()` answers "does this signature match these bytes". It has never
answered "should I accept this signer", and every version of the public
documentation says so.

That split is right, but the package stopped one step too early. **Not even the
mechanism was there.** An application that wanted to check a signature against
the ICP-Brasil chain had to take `$report->latest()->chain`, write the
certificates to disk itself and call OpenSSL by hand, which is the part the
package is supposed to be good at.

`Validation\ChainBuilder` already orders the embedded certificates leaf first
and confirms each link with the issuer's public key rather than by matching
names. What was missing was the last question: does the top of that chain reach
an authority the caller named.

## Decision

**The package ships no trust store and never will.** A bundled one is a
security-relevant artefact that goes stale between releases, and shipping it
would make this package's release cadence the thing that decides whose
signatures an application accepts. For ICP-Brasil the current chain is published
by the ITI; fetch it, keep it with the application's configuration, and hand it
in.

Verifying a chain against the roots the caller named is mechanism, and that
ships:

```php
$store = TrustStore::fromFile(storage_path('icp-brasil.pem'));

$report = A1PdfSign::validate($path, $store);

$report->isTrusted();                 // ?bool
$report->latest()?->isTrusted;        // ?bool, per signature
```

### Null, not false, when nobody was asked

A signature validated without a store reports `isTrusted` as **null**. It is not
untrusted; nobody was asked. Collapsing the two would let an application that
never configured a store read "untrusted" and conclude something the run never
established, and it is the same distinction
[0011](0011-signing-time-and-certificate-validity.md) drew for a signing time
that is absent.

An **empty** store is a different answer again: it trusts nothing, so every
signature reports false. `TrustStore::empty()` exists to make that sayable.

### OpenSSL does the path validation

`openssl_x509_checkpurpose()` builds and validates the path. Walking the chain
by hand would check that each certificate was signed by the next, which
`ChainBuilder` already does, and would silently skip everything else path
validation means: the validity window of each intermediate, `basicConstraints`
saying a certificate may act as a CA at all, key usage, name constraints and
path length.

**A hand-rolled check accepts chains a reader rejects**, which is the worst
direction for this answer to be wrong in. The roots go to one temporary file and
the intermediates to another, both deleted however the call ends
([0003](0003-temporary-files-outside-the-package.md)).

### Measured, before the code was written

| | |
|---|---|
| Leaf against its root, intermediate not supplied | **false**, so it really does build a path rather than compare issuers |
| Same, intermediate supplied as untrusted material | **true** |
| Leaf against an unrelated root | **false** |
| **Roots given as a directory of PEM files** | **false** |

The last one shaped the API. OpenSSL's CA-directory form needs the hashed
symlinks `c_rehash` creates, and a directory of plain files **silently verifies
nothing**: it returns false for a certificate whose issuer is sitting right
there. `TrustStore::fromDirectory()` therefore reads the files and concatenates
them into one bundle rather than handing the path to OpenSSL.

## Consequences

- `Contracts\SignatureValidator` and `Contracts\A1PdfSign` gain a trailing
  optional parameter, and `Data\SignatureDetails` gains a property, which
  changes the shape `toArray()` returns.
- `PdfSignatureValidator` takes `TrustVerifier` as an **optional** constructor
  parameter, so its arity does not move. A validator built by hand without one
  degrades to the same answer as a call with no store: trust unknown.
- ~~Revocation is still not evaluated.~~ **It is, since 2.4.** This section said
  the store's OCSP responses and CRLs were counted rather than read, and called
  that the next step of the same shape.
  [0024](0024-revocation-is-evaluated-not-counted.md) took it: the material is
  parsed, verified against the issuer and then read, and a response signed by a
  delegated responder is believed only once that responder is shown to have been
  issued by a certificate in the chain.

  The shape of the answer is the one this record argued for, and it is why the
  two stayed separate: `isRevoked()` is not `verified` and neither is
  `isTrusted()`. A revoked certificate still produces a signature that matches
  the bytes perfectly.
- `fromDirectory()` shipped in 2.3.0 calling `glob()` with the brace form and
  `GLOB_BRACE`. That constant is a GNU extension and PHP leaves it **undefined
  on musl**, so the method was a fatal error on `php:8.4-alpine` for the whole
  release while the suite stayed green: CI runs on Ubuntu, where the constant
  exists. It is now one `glob()` per extension, and `tests/ArchTest.php` fails
  on any platform-optional constant appearing in `src/`, since the behavioural
  test can only ever check the platform it happens to run on.

- `Support\Pem` came out of this. Four places had their own copy of
  `preg_match_all` over the certificate armour, and a fifth encoded DER back
  into it. Four copies of a pattern is four places to drop the `s` modifier, and
  the one that drops it reads a single certificate out of a bundle and calls it
  the chain.
