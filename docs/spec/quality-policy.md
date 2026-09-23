# Quality policy

The gates a change has to pass, and why each sits where it does.

**The gates that measure written bytes are not here.** Conformance (veraPDF,
PDF/A, PDF/UA), structure (qpdf), independent verification (`pdfsig`),
certification enforcement (pyHanko), the Arlington grammar, corrupted-input
robustness and mutation testing all measure what a writer writes, and this
package writes nothing. They run in
[signet-pdf](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/spec/quality-policy.md),
where the writer is ([0039](../decisions/0039-the-core-lives-in-signet-pdf.md),
[0026](../decisions/0026-verification-tools-are-instruments.md)).

What is left is what a wrapper can break.

## `composer check`, and it must pass before any commit

```
pint --test        code style, PER-CS
phpstan            level max, no baseline
composer deps      unused and shadow dependencies
pest               the suite, --fail-on-skipped
pest --type-coverage --min=100
```

Type coverage is in that list deliberately. It was a CI-only gate for one
release, and the first pull request to trip it was green locally and red in CI
on a number no local command reported. **A gate CI runs and the documented
local command does not is a gate discovered on a pull request.**

## PHPStan at level max, with no baseline

The baseline was deleted rather than shrunk. The gate is "no errors", not "no
new errors", and the only ignores are for Pest's untypeable fluent API, scoped
to `tests/*`.

`reportUnmatchedIgnoredErrors` is on by default and stays on: an ignore that
stops matching is itself an error, which is how the one left behind by a
deleted test file was found.

## Dead code is refused

PHPStan reports a private method nobody calls and a property only ever written.
A local variable assigned and never read is what it misses, so
`tests/Project/DeadCodeTest.php` walks the tree with `token_get_all()`.

It under-reports on purpose, and **unused public methods are deliberately not
checked**: the API exists for consumers whose code is not in this repository.

## Structure

`tests/Project/ArchTest.php` carries the rules that are about shape rather than
behaviour:

- only `Adapters\IlluminateProcessRunner` opens a process
- contracts are interfaces, facades extend Laravel's and are final
- console commands stay in `Commands`
- `laravel/ai` is named only in `src/Ai`, `laravel/mcp` only in `src/Mcp`, and
  nothing outside either names a class inside it
- the agent tools reach disks only through `Agents\DocumentAccess`, and sign
  and read through `Contracts\A1PdfSign` rather than the engine
- every file declares `strict_types=1`, checked twice: an arch expectation over
  `src/`, and a file walk for the files that declare no class
- no constant the host platform may not define
- every contract method appears in the README
- docblocks document parameters that exist

`tests/Project/SpecTest.php` walks every `.php`, `.md` and `.yml` and fails on
a cited path that does not resolve, or a cited symbol of this package that no
longer exists. It checks paths and symbols, not prose.

`tests/Project/DistributionTest.php` asks `git archive` what a release actually
contains, so nothing built for testing ships.

## The suite

Testbench, grouped by what it covers:

| Directory | Covers |
|---|---|
| `tests/Adapters` | the process runner, the encrypter, the transport |
| `tests/Io` | disks and uploads |
| `tests/Console` | the six artisan commands |
| `tests/Container` | bindings, config, the fake, what the engine can reach |
| `tests/Agents` | the path guard and the call ledger, which need neither SDK |
| `tests/Mcp` | the read tools and their server, group `mcp` |
| `tests/Ai` | the signing tool, and the tools inside a real AI SDK run with the model faked, group `ai` |
| `tests/Project` | the structural rules above |

### Without the optional SDKs

`laravel/ai` and `laravel/mcp` are dev requirements, so the main jobs always
have them. A separate CI job removes both and runs the suite with the `mcp` and
`ai` groups excluded, and without `tests/Project`, whose walks load every class
in `src/` including the two that cannot load without their SDK. It is what
turns "the SDKs are optional" from a claim into a check
([0040](../decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md)).

**Nothing skips.** `composer test` carries `--fail-on-skipped`, because every
check has to run somewhere and a skip is how one quietly stops.

There is no `network` group any more. Nothing here reaches an authority: the
transport is faked, and whether a real authority interoperates is a question
about the request, which signet-pdf builds.

## What a patch is expected to carry

A test. `tests/Project/ArchTest.php` is worth reading before adding a class,
since three of its rules constrain where things live.

For a change to behaviour, every surface `CONTRIBUTING.md` enumerates that
describes that behaviour. The
list is enumerated rather than summarised because "and any other relevant
documentation" is exactly what let three of them go stale at once.
