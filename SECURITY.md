# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 2.x | yes |
| 1.x | security fixes only |
| 0.x | no |

## Reporting a vulnerability

**Please do not open a public issue.** Use GitHub's private reporting, under
[Security → Report a vulnerability](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/security/advisories/new),
which reaches the maintainer without disclosing anything.

Include what you have: the version, a document or certificate that reproduces
it if one is safe to share, and what you expected instead. A signed PDF that
demonstrates the problem is worth more than a description of it.

You should get an acknowledgement within a few days. If a fix is warranted it
ships as a patch release with an advisory, and you are credited unless you would
rather not be.

## What counts

Anything that makes this package report a signature as sound when it is not, or
produce a document whose signature is weaker than the caller asked for. Some
specific shapes, because they are the ones worth thinking about here:

- a document whose bytes were altered after signing that still reports as valid;
- a signature attributed to the wrong certificate, or a chain accepted against a
  trust store that should not reach it;
- revocation material that is believed without being verified against its issuer;
- a timestamp accepted without checking that it stamps the signature it sits in;
- key material, a certificate password or a document password reaching a log, an
  exception message or a temporary file that outlives the call.

## What does not

Some behaviour looks like a weakness and is a documented decision. Reporting one
of these is welcome as an issue, and it will be closed with a link rather than
an advisory:

- **The package ships no trust store**, so `isTrusted()` answers `null` until you
  supply one. Whose signatures to accept is the application's policy
  ([0016](docs/decisions/0016-trust-is-the-applications-policy.md)).
- **Validation never reaches the network.** Revocation is evaluated only from
  what the document carries, so a certificate revoked after signing, with nothing
  in the file saying so, reports `Unknown` rather than `Revoked`
  ([0024](docs/decisions/0024-revocation-is-evaluated-not-counted.md)).
- **RC4-encrypted documents are refused rather than signed**, because signing one
  means writing RC4 back into it
  ([0030](docs/decisions/0030-signing-a-document-that-is-encrypted.md)).
- **A self-signed certificate can satisfy `IcpBrasilReport::conforms()`.** That
  check is structural, and it says so everywhere it appears
  ([0029](docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md)).
- **Test-only classes** under `Testing\` generate throwaway certificates and a
  local timestamp authority. They are excluded from release archives, which
  `tests/DistributionTest.php` checks.

## Handling of secrets

Every password argument carries `#[\SensitiveParameter]`, so it does not appear
in a stack trace. Temporary files are deleted in a `finally` block and again in a
destructor. Network access happens only through `Contracts\SignatureTransport`,
which an application can rebind to route every outbound request through its own
client, proxy or allowlist.
