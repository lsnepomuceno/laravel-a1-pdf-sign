# Quality policy

The gates a change has to pass, and why each one is set where it is.

`composer check` runs the whole of it and must pass before any commit:
`pint --test`, `phpstan`, `deps`, `pest`.

> Written from the toolchain as configured. The v2 plan's §6 proposed a
> `bc-check` job, a `test:arch` script and a set of arch rules that were never
> built as written; that section is kept in
> [the modernisation record](../history/v2-modernization.md).

## Composition

| Layer | Tool | Gate |
|---|---|---|
| Formatting | `laravel/pint`, `per` preset | `pint --test` |
| Static analysis | PHPStan 2 + Larastan 3 + strict-rules + deprecation-rules | `level: max`, **no baseline** |
| Tests | Pest 5 | green on PHP 8.4 and 8.5 against Laravel 13 |
| Architecture | Pest Arch, `tests/ArchTest.php` | rules as tests |
| Specification | `tests/SpecTest.php` | every cited section resolves |
| Type coverage | `pest-plugin-type-coverage` | `--min=100` |
| Line coverage | PCOV | informational, no gate |
| Mutation | `pest-plugin-mutate` | per-namespace `--min`, nightly |
| Dependencies | `composer-dependency-analyser` + `composer-normalize` | unused and shadow deps |
| Git hooks | Husky | pre-commit Pint on staged files |

## PHPStan runs at `level: max` with no baseline

The baseline was deleted, not shrunk. **The gate is "no errors", not "no new
errors"**: a baseline must only ever track debt that can actually be paid down,
and this one had none left.

The single exception is scoped and documented in `phpstan.neon`: Pest's fluent
API (`arch()`, `expect()->and()->not`, dataset chains) is runtime magic that
PHPStan cannot type without a dedicated extension. Those are ignored by
identifier under `tests/*`, because they are limits of the tooling rather than
defects.

`poc/` is excluded: it holds throwaway spikes, not production code.

## Type coverage is gated at 100%

## Mutation testing

Covers `src/Certificates`, `src/Signing` and `src/Validation`, the three
namespaces where a test that only asserts "it did not throw" would keep passing
with broken cryptography.

**It runs nightly, not on pull requests** (`.github/workflows/mutation.yml`),
one runner per namespace, each with its own floor:

| Namespace | Measured | Floor |
|---|---|---|
| `src/Certificates` | 64.71% / 61.76% | 58 |
| `src/Signing` | 66.02% / 67.50% | 62 |
| `src/Validation` | 77.68% / 77.68% | 75 |

Three rules govern this, and each cost something to learn:

**The score is not reproducible.** It tracks how many mutations time out, which
tracks machine load. A mutation that breaks a loop condition burns the full
timeout, which the plugin derives from the suite duration and does not expose as
an option. `Certificates` shells out to `openssl` and swings three points
between identical runs; `Validation` times out twice and does not move at all.
Every floor therefore sits below the lowest observed value for its namespace.

**Raise a floor only after measuring it.** Never set a target ahead of the
measurement, and never lower one to make a run pass.

**Never split with `--shard`.** It divides the *test suite*, and every mutation
needs the whole suite: a mutation killed by a test that landed in another shard
is reported as uncovered. Measured on `src/Certificates`, the full run scores
64.71% with 8 uncovered, while shard 1/2 reports 61.76% with 26 uncovered and
shard 2/2 reports 69.12%. Faster precisely because it is wrong. Split by mutated
path instead.

### `phpunit/php-code-coverage` is held below 14.2.4

**`>=14.2 <14.2.4` in `require-dev`. Remove it once `pest-plugin-mutate` can
consume the newer format.**

14.2.4 changed the shape of `lineCoverage()`: the plugin expects each covered
line to carry a list of test identifiers, and gets integers instead. It dies
before scoring anything:

```
TypeError: preg_match(): Argument #2 ($subject) must be of type string, int given
  at vendor/pestphp/pest-plugin-mutate/src/MutationTest.php:54
```

It reproduces with and without `--parallel`, on both `pest-plugin-mutate`
5.0.0 and 5.0.1, so it is neither the plugin version nor the parallel runner.

This is the only pinned dependency in the package, and it exists because the
nightly resolves everything unpinned on every run: a release anywhere in the
test stack can break the job overnight, which is exactly what happened on
2026-08-08.

Not a pull-request gate for two reasons that follow from the above: a run costs
~2600 process-seconds against ~30 seconds for every other check, and a blocking
gate that moves three points on its own eventually fails a pull request that
changed nothing. A gate contributors learn to re-run has stopped being a gate.
`workflow_dispatch` runs it on demand before a release, and a failing run opens
or updates a tracking issue per namespace.

**The schedule is when it may run, not what it measures.** A `changed` job
compares the default branch against the commit of the last run that reached a
verdict, and skips when nothing has landed since, so a given commit is scored
once, not once per night.

Re-scoring identical code answers a question already answered, and answers it
*differently*: the score is not reproducible, so a quiet week would produce a
week of contradictory numbers for the same tree, and any of them could trip a
floor. Cancelled runs are excluded from the comparison: concurrency cancels
them mid-flight, so their commit was never actually scored.

The cost is that this job stops doubling as a canary for the unpinned
dependency resolution. It was playing that role by accident, and playing it
well: the `php-code-coverage` break of 2026-08-08 arrived with no commit
behind it and was caught by a nightly on untouched code. During a quiet period
that break would now surface only at the next merge.

## CI

`.github/workflows/main_action.yml`, on pull requests to `main` only, since every
change reaches `main` through a pull request, so building branch pushes as well
duplicated each run.

| Job | Runs |
|---|---|
| `PHP 8.4 / 8.5 - Laravel 13` | the suite, plus a second pass against a live timestamp authority |
| `Code style and static analysis` | Pint, dependency analyser, `composer.json` formatting, PHPStan |

**Laravel 12 is not supported**, despite reaching PHP 8.5: it requires
`symfony/process ^7.2` while Pest 5 requires `^8.1`, so the two cannot be
installed together and the cell fails at `composer update` before a test runs.

Tests in the `network` group hit a live timestamp authority (freetsa.org) and
fail offline. Exclude them with `--exclude-group=network`.

## Tests

Orchestra Testbench, not a host application. **`openssl` on `PATH` is not
required to run the suite**: `Testing\DebugCertificate` generates throwaway
PKCS#12 and PEM bundles through the ext-openssl functions.

Helpers shared across test files must live in `tests/Pest.php`. A helper defined
inside one test file is invisible to the others under `--parallel`, which fails
as `Call to undefined function`.

Patches are expected to come with tests. `tests/ArchTest.php` enforces the
structural rules, so read it before adding a class.

Independent verification is done with poppler's `pdfsig`; it has caught bugs the
suite passed straight through. `samples/` holds one signed PDF per profile plus
a six-signature document. Regenerate them with `poc/sign-samples.php` and
re-check after any change to `src/Signing/`.

**One trap in that cross-check.** `Testing\DebugCertificate` gives every
certificate it generates the same subject, `CN=Test Certificate, O=Internet
Widgits Pty Ltd`, and so does `samples/certificate.pfx`. `pdfsig` resolves the
signer through NSS **by name**, so a document carrying signatures from two
different keys under that one subject has its later signatures matched against
the wrong certificate and reported as *Signature is Invalid*.

It is a name collision in the checker, not a defect in the document: the
package's own validator reads the certificate embedded in each CMS and reports
the same file as valid, and re-signing a sample with the certificate that made
it clears the report. Sign a sample with `samples/certificate.pfx` before
concluding anything from a `pdfsig` failure on a multi-signature file.

## Development environment

The local floor is PHP 8.4 and the matrix reaches 8.5, so version-specific work
goes through `.docker/`:

```bash
docker compose -f .docker/compose.yaml run --rm php85 composer check
```

Services `php83`, `php` (8.4) and `php85`, each keeping `vendor/` in its own
named volume so switching versions does not invalidate the other install. The
image ships `openssl`, `gd`, `imagick` and **`pcov`**, the last required by
coverage and mutation and absent from the official images.

That volume **masks the host `vendor/`**, which is why an IDE reports missing
classes after a Docker-only install. Fix it with `composer install
--ignore-platform-reqs` on the host.

## Git hooks

Husky runs Pint on the staged PHP files before the commit is created, then
re-stages the result, so the formatting a contributor pushes is what CI expects.
`npm install` enables it.

| Decision | Rationale |
|---|---|
| Husky over CaptainHook/GrumPHP | Both install into `require-dev`, and therefore into the resolver the package must keep unblocked across Laravel majors. Husky lives in `package.json`, outside Composer entirely. |
| Only Pint, not `composer check` | A pre-commit hook must be fast. PHPStan and the suite take minutes and belong in CI. |
| Formats and re-stages rather than failing | Failing on a fixable difference makes the contributor run the fixer and commit again. Fixing it is the same outcome, one step earlier. |
| Falls back to Docker | Pint requires PHP 8.2 and the package floor is 8.4; a maintainer on an older host still needs the hook to work. |

Node is **not** a dependency of the package: `package.json` is private and
`export-ignore`d. A contributor without Node loses the hook and nothing else.

## Modern PHP

The criterion: a feature gets adopted when it **removes code or removes a class
of bug**.

- `#[\SensitiveParameter]` on every password argument, a security fix disguised
  as modernisation, one line per signature, keeping certificate passwords out of
  stack traces and logs.
- `#[\Override]` on contract implementations: the compiler guarantees the
  signature still matches.
- Typed class constants, `final readonly` by default, enums carrying behaviour
  instead of class constants.

Deliberately excluded: the pipe operator `|>` and `clone with`, which would
require an 8.5 floor and cut every host still on 8.4.

## Why `src/Support` is scored

It was not, until two helpers moved there. `PdfDictionary` came out of
`Validation` and `Signing`, which each had their own copy of it, and `PdfStream`
came out of `Signing` the same way. Extracting them removed real duplication and
**silently took the code out of the gate it had been under**, since the nightly
matrix names namespaces rather than following the code.

The floor is provisional. One measurement, 83.44%, where the rule above asks for
two consecutive ones, so it sits at 65 rather than close. Tighten it after the
second nightly run, and not before: a floor set from one measurement of a score
that moves with machine load is a floor that fails a night when nothing changed.

## Why the backward compatibility check reports rather than blocks

It compares the last SemVer tag against `HEAD` and writes what it found into the
job summary, without failing the build.

That is a deliberate weakening, decided after the check fired on its second real
pull request. **Every release since 2.0 has added a method or a parameter to a
published contract**: 2.1 added `signFromPem()`, 2.2 added `signatureFields()`
and two parameters to `PdfSigner::sign()`, 2.3 added a parameter to
`SignatureValidator` and `A1PdfSign`. Each shipped as a minor with a "Breaking
for implementers" section, because calling the contracts is unaffected and only
implementing them is not.

A gate that fails on every release of that shape is a gate that gets switched
off within two of them, and one nobody reads is worse than one that reports. The
report is the point: it caught a break in 2.2 that was not deliberate, the
signer's constructor arity, which is exactly what a person needs told and not
what a person needs blocked.

The judgement it informs stays a judgement. A break is answered in
[UPGRADE.md](../../UPGRADE.md), in the release notes and in the version number.
