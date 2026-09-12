# 0039: The core lives in signet-pdf, and this package is the Laravel adapter

**Status:** decided. Implementation is tracked by #313.

## Context

This package is two things wearing one name.

One is a PDF signing engine: `Signing`, `Validation`, `Certificates`, `Seal`,
the ASN.1 readers, the incremental writer, the security store, the ICP-Brasil
layer. Around 14,700 lines. **Nothing in it touches the framework.**
`docs/spec/conventions.md` already forbids `Illuminate\Support\Str` inside
`src/Signing` and `src/Validation`, because multibyte offsets over PDF or DER
corrupt a signature, and `tests/Project/ArchTest.php` fails on any use of it
there. The rule exists precisely because the engine has no business knowing
about Laravel.

The other is the adapter: a service provider, a facade, a config file, three
artisan commands. A few hundred lines.

`lsnepomuceno/signet-pdf` is the first of those two, extracted, framework free
and published. Its 3.0.0 went out on 2026-09-02. It carries everything listed
above plus work this package does not have: signing a document larger than
memory, a signature produced without the private key entering the process, a
receipt saying what was embedded and what was not, ICP-Brasil policy
declaration accepted by the country's own Verificador.

The two have already begun to diverge, and
[0038](0038-the-envelope-is-versioned.md) is what that looks like from here:
signet-pdf changed its encryption envelope, material sealed there stopped
opening here, and this package had to grow a reader for a format it does not
write. That is the cheap version of the problem. The expensive version is the
same defect fixed twice, or worse, fixed once.

## Decision

**The engine leaves. v3 of this package is the Laravel adapter over
`lsnepomuceno/signet-pdf` and nothing else.**

### What is kept, and why it is still a package

Four of signet's contracts get a Laravel implementation, and each one buys
something the standalone package cannot have:

| Contract | Laravel implementation | What it buys |
|---|---|---|
| `Contracts\ProcessRunner` | `Illuminate\Process\Factory` | `Process::fake()` keeps covering every shell out |
| `Contracts\Encrypter` | `illuminate/encryption` | the vault uses the application's own key management |
| `Contracts\SignatureTransport` | `Illuminate\Http\Client\Factory` | `Http::fake()` reaches the TSA, OCSP and CRL calls |
| `Contracts\PdfSource`, `PdfDestination` | `Storage` disks, `UploadedFile` | signing a document that lives on S3 without pulling it into a local file |

Plus the container, `config/a1-pdf-sign.php` building a `Signet\Config\SignetConfig`,
the artisan commands and `A1PdfSign::fake()`.

**The first of those is not a convenience.** Rule 8 of
[the invariants](../spec/invariants.md) says only one audited helper spawns a
child process, and it is built on Laravel's factory so a consuming application
can fake it. Resolving signet's Symfony runner instead would leave that rule
technically satisfied and practically dead: `Process::fake()` would stop
covering `OpenSslCliCertificateReader` and the signature verifier, and nothing
in a consumer's suite would report it.

### Clean break on names

The consumer imports `LSNepomuceno\Signet\Data\*`, `Enums\*` and `Exceptions\*`
directly. No aliases, no deprecated subclasses, no permanent re-export under the
old namespace. `UPGRADE.md` carries the map, as it did for 1.x to 2.0.

This is decision 12 of [the decision log](../history/decision-log.md) applied a
second time, and for the same reason: a shim kept "until 4.0" is kept
indefinitely, and each one constrains the design it wraps.

### The heavy gates leave with the bytes they measure

veraPDF, qpdf, `pdfsig`, pyHanko, the Arlington model, the corrupted input
sweep and mutation testing all measure written bytes.
[0026](0026-verification-tools-are-instruments.md) calls them instruments, and
an instrument belongs where the thing it measures is produced. After this change
nothing here produces a byte of PDF.

What is left is an adapter suite on Testbench: the container resolves, the
config maps, the fakes intercept, the commands exit correctly, and one smoke
case signs a real document end to end.

## Consequences

- **`composer.json` loses `tecnickcom/tc-lib-pdf-sign`, `symfony/http-foundation`,
  `intervention/image` and four extensions**, and gains one dependency. The PHP
  floor moves from 8.4 to 8.4.1, and Intervention Image becomes transitive at
  `^4.3` rather than direct at `^3.11`, which is an installation break for an
  application pinned to 3.
- **Laravel 13 already installs Symfony 8**, which is what signet requires
  across `console`, `http-client`, `process` and `uid`. There is no version
  conflict to resolve, unlike the Laravel 12 cell in
  [0005](0005-php-and-laravel-floor.md).
- **The nightly mutation workflow is deleted rather than retargeted.** Its three
  namespaces all leave.
- **Most of [the invariants](../spec/invariants.md) stop being ours.** Rules 1
  to 6 describe an engine that will be in another repository. What survives here
  is rule 7 (no container cycle), rule 8 and rule 9 restated as bindings that
  must never be left at signet's default, and rule 10.
- **A defect in signing is now fixed in one place and released twice**, which is
  slower for a consumer of this package by exactly one release cycle. That is
  the cost, and it is worth paying against the alternative of the same defect
  being fixed in one place and not the other.

## Alternatives rejected

| | Why not |
|---|---|
| Keep both, and sync by hand | Two copies of an incremental writer is two places for rule 3, operating on the last match, to be broken in, and only one of them has poppler watching. It is also what produced [0038](0038-the-envelope-is-versioned.md) |
| Depend on signet but keep a thin engine here for compatibility | The thin engine is the part that writes bytes. There is no version of it that is thin |
| Make signet a `suggest` and let the consumer choose | Two code paths for the same operation, and the one nobody runs is the one that breaks |
| Deprecate over a release rather than break | The floor already forces a deliberate upgrade. Adding a shim on top buys a consumer one release of not editing imports, and costs this package a namespace it can never reshape |
| Fold the Laravel adapter into signet instead | It would put `illuminate/*` in the dependency list of a package whose selling point is that it has no framework |

## Outcome

Written back when the line ships: what actually moved, what the wrapper turned
out to need that this record did not anticipate, and whether the adapter stayed
as small as it looks here.
