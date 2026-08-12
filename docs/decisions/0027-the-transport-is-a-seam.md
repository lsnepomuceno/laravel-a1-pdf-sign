# 0027: The transport is a seam, so the profiles can be gated

**Status:** implemented.

## Context

Invariant 9 says network access stays behind the injected transport, and it was
true: `Signing\Cades\HttpTransport` is the only thing in `src/` that opens a
connection, and it is constructor-injected into `CadesBuilder`, `DssWriter` and
`DocTimeStampWriter`.

**It was injected and could not be substituted.** The class was `final readonly`
and all three collaborators depended on the concrete type, so the only way to
exercise anything above `pades-b-b` was to reach freetsa.org.

That put the package's most important behaviour in the `network` group, which is
deliberately **non-blocking**, because an outage on somebody else's side is not
a defect here. The consequence is what matters:

| Behaviour | Status before |
|---|---|
| signing at B-T, B-LT, B-LTA | reported, never gated |
| the archive timestamp chain ([0022](0022-the-archive-timestamp-is-a-chain.md)) | reported |
| verifying the signature timestamp ([0019](0019-validation-reads-what-it-writes.md)) | gated only against a committed sample |
| PDF/A conformance at B-T and B-LTA ([0025](0025-what-signing-does-to-pdf-a.md)) | reported |

Three of the five PAdES levels this package advertises could regress without
turning CI red. That was already demonstrated once: three network tests were
committed broken, went green, and were only noticed by reading the log.

**Injection you cannot substitute is not injection.**

## Decision

`Contracts\SignatureTransport` names the three calls, `HttpTransport` implements
it, and the container binds one to the other. The collaborators depend on the
interface.

### The substitute is a real authority, not a stub

`Testing\LocalTimestampAuthority` answers with tokens from `openssl ts -reply`,
which is a complete RFC 3161 responder needing no server and no connection. The
tokens are **signed, verifiable, and carry the imprint of the bytes they were
handed**: `SignatureVerifier` checks them through OpenSSL exactly as it checks
freetsa.org's, and the offline tests assert `timestampVerified === true` rather
than asserting that something was embedded.

A stub returning canned bytes would have been easier and would have proved
nothing: the imprint has to match the signature value produced in that run, so
only a real responder can answer.

The certificate it signs with carries the `timeStamping` extended key usage,
which RFC 3161 §2.3 requires and without which `openssl ts` refuses to sign at
all.

### What it deliberately is not

It is not a third party. A local authority establishes that the package builds a
request correctly, embeds the reply correctly and verifies it correctly. It
cannot establish that the package interoperates with somebody else's TSA.

**So the live tests stay.** `PadesTest`, `DssTest` and the PDF/A ones against
freetsa.org keep running in the `network` group, reported rather than blocking.
The two answer different questions and the offline ones do not replace them.

### A container was considered

`uts-server` and EJBCA both provide a TSA, and `verapdf-rest` is the equivalent
idea for the validator. All of them relocate the dependency rather than remove
it: the gate would need a service up, container networking and a second thing to
pin. `openssl ts -reply` is already in the image, because the package shells out
to OpenSSL for signature verification anyway.

## Consequences

- **Six behaviours move from reported to gated**, including PDF/A conformance at
  B-LTA, which [0025](0025-what-signing-does-to-pdf-a.md) could only measure
  against a live authority.
- `Contracts\SignatureTransport` is new, and the three collaborators' constructor
  types change from the concrete class to it. That is a break for anyone
  building them by hand, which the Roave check reports.
- `HttpTransport` keeps its name, its behaviour and its place. Nothing about the
  production path changes.
- `Testing\LocalTimestampAuthority` is test-only and kept out of the production
  classes, exactly as `Testing\DebugCertificate` is. It needs the `openssl`
  binary, which `Validation\SignatureVerifier` already does.
- The offline tests are **not** in a group. They run in the ordinary suite,
  which is the point.

## Alternatives rejected

| | Why not |
|---|---|
| A TSA container | Relocates the dependency: a service to start, a network to cross, a second thing to pin |
| A stub returning canned bytes | The imprint must match the run's own signature value. Canned bytes prove nothing |
| Drop `final` from `HttpTransport` instead | Substituting by subclassing is a seam by accident rather than by design |
| Replace the live tests with the offline ones | Only the live ones establish that a real authority is understood |
| Leave the profiles in the network group | Three of five advertised levels could regress without CI going red |
