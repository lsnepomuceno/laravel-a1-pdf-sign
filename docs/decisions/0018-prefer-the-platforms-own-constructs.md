# 0018: Prefer the platform's own constructs

**Status:** implemented.

## Context

Two habits had been accumulating, and they are the same habit.

**The package hand-rolls things Laravel ships.** It requires
`illuminate/support`, `illuminate/http`, `illuminate/process` and
`illuminate/filesystem` outright, and it is not usable outside the framework at
all: the facade, the service provider and the container bindings are the API.
Everything in those packages is therefore already installed, already tested and
already familiar to whoever reads the code. `Signing\Cades\HttpTransport` builds
a `stream_context_create` and calls `file_get_contents` anyway, where `Http::`
would give timeouts, retries and `Http::fake()`.

**And it reaches for class constants where a set of values exists.** PHP has had
enums since 8.1, the floor here is 8.4, and `Enums\SignatureProfile` and
`Enums\CertificationLevel` already show what that buys: the language checks the
set, the IDE completes it, and `resolve()` can turn a configuration string into
the type once instead of at every call site. A group of related `const int`s is
the same thing with the checking removed.

Neither is a defect today. Both are the kind of thing that is invisible for a
release and then everywhere.

## Decision

**Use what the platform provides. Write the bespoke version only after
establishing there is no platform version, and say so in the docblock.**

Both halves are stated as standing rules in
[the conventions](../spec/conventions.md), which is where someone reads them
before writing code. This record is why.

### The framework first

The rule is "look, then use, then if it genuinely is not there, write it in
`src/Support/` with a docblock that says what was missing". A helper whose
docblock cannot answer "why is this not `Str::something`" is a helper that
should not exist.

`Support\Files::read()` is the shape a legitimate one takes: it exists because
`file_get_contents()` **and** `File::get()` both return `false`, and that `false`
reaching a `string` parameter was this package's single most common typing
defect. The docblock says exactly that.

### Except on bytes, where it is the opposite

**`Str::substr()` and `Str::length()` are multibyte-aware.** Running them over a
PDF or a DER blob reinterprets binary as UTF-8, so the offsets come back wrong,
and in this package a wrong offset is a corrupted signature.

This is not a style preference with a caveat. It is the one place where reaching
for the framework helper is a defect, and it is a defect that **passes the whole
test suite**, because the fixtures are ASCII and the failure needs a multi-byte
sequence in the payload to show up.

So the carve-out is enforced bluntly: `tests/ArchTest.php` fails when
`Illuminate\Support\Str` is used anywhere in `src/Signing` or `src/Validation`.
Those two namespaces are where every byte-exact operation lives. "Not here at
all" is a rule that survives review; "here, but only these five methods" is not.

`preg_match` and `preg_match_all` stay for the same reason: `Str::match()`
returns the matched text and discards the offsets, and offsets are what the
incremental writer is built on.

### Enums for sets, constants for facts

A closed set of values is an enum. A constant is for a lone fact: one cipher,
one reserved width, one placeholder shape, one path.

The test is **"could a second value of this kind ever be right?"** If it could,
it is an enum now, because otherwise the sibling arrives later and arrives as a
constant beside the first one, and by then there are three call sites comparing
integers.

**Enums nobody configures may be int-backed.** The existing arch rule requires
string-backed enums so configuration can name a case in plain text, and that
reason does not reach an ASN.1 tag whose values are fixed by ISO/IEC 8825-1 and
are natural integers. Those are exempted by name, the way `sha1` is exempted for
`SignatureDetails`, rather than by weakening the rule for every enum.

## Consequences

- `tests/ArchTest.php` gains two rules: no `Illuminate\Support\Str` in
  `src/Signing` or `src/Validation`, and the string-backed requirement now names
  its exemptions instead of applying to every enum.

- **`HttpTransport` is left as it is, and recorded as outstanding.** Moving the
  TSA, OCSP and CRL calls onto `Http::` is the right change and it is not a
  documentation change: it alters the network path of every `pades-b-t` and
  above, and the tests that would cover it are in the `network` group. Doing it
  inside a documentation commit would be exactly the quiet behaviour change this
  project keeps saying it does not want.

- **`Data\SealPlacement::LAST_PAGE` stays an `int` sentinel**, against the enum
  rule. It was `Enums\SealPage` and was deliberately removed during the v2 work,
  and reversing that now would change the type of a public property for a
  cosmetic gain. Named in the conventions so the next reader finds a decision
  rather than an oversight.

## Alternatives rejected

| | Why not |
|---|---|
| Leave both as review-time taste | Taste does not survive a busy week. The `Str` half is a defect the suite cannot catch, so it needs a gate |
| Ban `Str` package-wide | `Support\TemporaryFile` and `Data\SignedPdf` use `Str::orderedUuid()` correctly, and naming a temporary file is not byte work |
| Allow `Str` in `Signing` and `Validation` with a method allowlist | An allowlist is a rule you have to read to apply. "Not in these two namespaces" is one you can apply from memory |
| Convert every existing constant to an enum in one sweep | Most of them are lone facts and would become single-case enums, which is ceremony without checking |
| Rewrite `HttpTransport` here | A behaviour change riding in on a conventions commit, with its tests in the group that needs the network |
