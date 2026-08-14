# 0038: The envelope is versioned, so material sealed by signet-pdf still opens

**Status:** implemented.

## Context

`Certificates\CertificateVault` seals a certificate and its password with
`Illuminate\Encryption\Encrypter`, AES-128-CBC under a 16-byte key, in Laravel's
envelope: `base64(json({iv, value, mac, tag}))`.

`lsnepomuceno/signet-pdf`, the core extracted from this package, reproduced that
format byte for byte for one reason: an application moving between the two
cannot re-encrypt a certificate whose plaintext it no longer holds. Material
sealed by either opened in the other, and that guarantee was stated in both
codebases.

**Its 2.0 moved new material onto XChaCha20-Poly1305 through `ext-sodium`**,
under a 32-byte key and a `signet.v2.` envelope, on the grounds that a signing
package should not be assembling encrypt-then-MAC by hand. That reasoning holds
and the change was right there. What it did here was break the guarantee in one
direction: what this package writes still opens there, and what that package now
writes does not open here at all. `withKey()` would not even accept the key,
because a 32-byte string is not a valid AES-128-CBC key.

## Decision

**Read both envelopes. Keep writing this one.**

`Support\SodiumEncrypter` implements the `signet.v2.` scheme, and
`CertificateVault::withKey()` picks the reader from the key's length: 16 bytes
is this package's own, 32 is signet-pdf's. Those are the only two lengths either
package has issued, so the mapping is total, and any other length is refused
rather than padded into one of them.

**`create()` is unchanged and still writes Laravel's envelope.** This is a
compatibility fix, not a migration: every application storing material from this
package keeps storing exactly what it stored, and nothing has to be re-encrypted
or re-sized. Adopting the new scheme here is a separate decision with a
storage-width consequence, and it can be taken later on its own terms.

### Not the fuller contract

The vault's property is typed `Illuminate\Contracts\Encryption\StringEncrypter`,
which Laravel's `Encrypter` satisfies alongside the contract it also implements.

The fuller `Encrypter` contract was rejected: satisfying it means implementing
`decrypt($payload, $unserialize = true)`, and offering an `unserialize()` path
over attacker-supplied bytes to solve a compatibility problem is a trade nobody
should take. `StringEncrypter` is exactly the two methods the vault calls.

That leaves `getKey()` unavailable, so **the vault holds its own key** rather
than reading it back off the encrypter. It was always the vault's key: it is
what `seal()` returns and what `withKey()` is given.

### The marker is authenticated

`signet.v2.` is passed as the AEAD additional data rather than merely prepended,
so an envelope whose marker is edited fails to open instead of being routed to
another reader. That is signet-pdf's decision reproduced byte for byte, and
changing any detail of it on this side would break the thing it exists to
preserve.

## Consequences

- **`ext-sodium` joins `require`.** It ships with PHP and has since 7.2, so on
  most systems this changes nothing, but a build compiled without it now fails
  at `composer install` rather than at runtime.

- **`CertificateVault::encrypter()` returns `StringEncrypter`** where it
  returned `Illuminate\Encryption\Encrypter`. Widening a return type is a
  backward-compatibility break for anyone who type-hints the concrete class.
  Nothing inside this package or its tests calls it, so the blast radius is
  whatever consumers do with it, and this is the one thing in the change worth
  arguing about.

- `withKey()` now raises `InvalidCertificateContentException` for a key of any
  other length, where it previously let Laravel's encrypter refuse it.

## Alternatives rejected

| | Why not |
|---|---|
| Move this package to the new envelope too | A second breaking change, with a storage-width consequence for every consumer, to solve a problem that reading solves |
| Implement the full `Encrypter` contract | It requires `decrypt()` with an `unserialize()` path over supplied bytes. Not for a compatibility fix, not for anything |
| A union type, `Encrypter\|SodiumEncrypter` | Says less than the contract does, and closes the type to a third implementation for no gain |
| Leave it, and let signet-pdf's material stay unreadable here | It breaks a guarantee both codebases state in prose, and the direction it breaks is the one a migration needs |
| Wait for this package to be rebuilt on signet-pdf | That decision is open and may not land for months. The material is unreadable now |
