# 0035: The audit trail is opt-in, and its context is an allowlist

**Status:** implemented.

## Context

Digital signatures in corporate systems are audited. Who signed, when, at what
level, and which documents failed to verify are questions somebody eventually
has to answer, and nothing here recorded any of them.

The package runs only inside Laravel, so a PSR-3 logger is available from the
container and this is a wiring question rather than a dependency one.

## Decision

**Null by default, injected rather than resolved.**

A package that logs unasked fills somebody's disk, and a host that wants an
audit trail knows it. `Support\SigningLog` takes an optional
`Psr\Log\LoggerInterface` and does nothing without one, and it is a constructor
argument rather than a `Log::` call inside the signer, because a dependency
reached through a facade is invisible and untestable.

**The context is an allowlist, and that is the load-bearing part.**

This package handles PKCS#12 bundles, private keys and passwords. Every password
argument is already marked `#[\SensitiveParameter]`, which keeps the value out
of a stack trace and has nothing whatever to say about a line somebody wrote to
disk. A logger is a second channel, and it needed its own answer.

So `SigningLog::ALLOWED` names the keys that may appear, and anything else is
dropped. **A denylist would have been the wrong shape**: it is how the next
property added to a data object ends up in a log file, silently, because nobody
remembered to forbid it.

Values are scalars only. An object arriving under an allowed name could carry a
key into whatever formats the line, and the format is the host's choice rather
than ours.

Absent on purpose, though they would be useful: the document, the CMS, the PFX
bytes, any password, and **any file path**. A path is enough to find the bundle
it names.

**The event names are an enum.** A closed set of values is an enum here
([0018](0018-prefer-the-platforms-own-constructs.md)), and there are four of
them rather than one per internal step: an audit trail and a debug trace want
different retention, and mixing them produces neither.

## Consequences

- Nothing changes for a host that does not ask. The default constructor argument
  is a `SigningLog` with no logger, and the arity of `IncrementalSigner` grows by
  one defaulted parameter, which is how 0021 and 2.2 taught this codebase to add
  a dependency without breaking a hand-built signer.
- `tests/Support/AuditLogTest.php` asserts, for every event, that no password, no key
  and no path appears in any line. That test is the reason the allowlist can be
  trusted rather than merely intended.
- Validation logs its verdict, including failure. A failed validation is the
  event most worth auditing and also the one an attacker can trigger in bulk by
  feeding bad documents, so a host that exposes validation publicly should rate
  limit before it enables this.

## Alternatives rejected

| | Why not |
|---|---|
| Log by default | Fills a disk nobody asked to fill, and makes the package noisy in every application that installs it |
| Resolve `Log::` inside the signer | Invisible, and untestable without touching the facade |
| A denylist of forbidden keys | The next property added to a DTO leaks, and nothing fails |
| Log the document or the CMS | Enormous, and the CMS carries the signature it is supposed to protect |
| Log the certificate path | Enough to find the bundle, which is the thing being protected |
