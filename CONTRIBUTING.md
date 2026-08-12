# Contributing

Contributions are **welcome** and will be fully **credited**.

We accept contributions via Pull Requests on [Github](https://github.com/lsnepomuceno/laravel-a1-pdf-sign).


## Pull Requests

- **Nothing is pushed to `main`.** Every change arrives through a pull request, without
  exception, and that includes documentation, a one-line typo and a release note. Branch,
  push the branch, open the pull request. A `pre-push` hook refuses a push that lands on
  `main` before it leaves your machine, because the server-side rule can be bypassed by
  anyone with the permission to bypass it, and on 2026-08-10 it was: two documentation
  commits went straight in and had to be reverted and reapplied through
  [#238](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/pull/238) and
  [#239](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/pull/239). Pushing a release
  tag is the one thing that goes to the remote directly.

- **Code style is [PER-CS](https://www.php-fig.org/per/coding-style/)**, enforced by [Pint](https://laravel.com/docs/pint). Run `composer lint` to apply it; CI runs `vendor/bin/pint --test` and fails on any difference.

- **Static analysis must stay clean** - PHPStan runs at `level: max` with no baseline, so the gate is "no errors", not "no new errors". Run `composer analyse`.

- **Reach for Laravel before writing a helper** - the package only runs inside the framework, so
  `Str`, `Arr`, `File`, `Http` and the rest are already there. Write your own only after
  establishing there is no framework equivalent, and say so in the docblock. The one exception is
  byte work on PDF and DER, where the multibyte helpers return wrong offsets: see
  [the conventions](docs/spec/conventions.md).

- **A set of values is an enum, not a group of class constants** - constants are for a lone fact,
  like one cipher or one reserved width.

- **Add tests!** - Your patch won't be accepted if it doesn't have tests.

- **Document any change in behaviour, in every place that describes it.** "And any other relevant
  documentation" is the phrasing this used to carry, and it is how three surfaces went stale at
  once: `samples/` sat a whole release behind, the documentation site stopped at 2.3.1 while 2.4
  shipped, and the README never mentioned two facade methods that had been public for a release.
  The list is enumerated below precisely so it cannot be read as "the obvious ones".

- **Consider our release cycle** - We try to follow [SemVer v2.0.0](http://semver.org/). Randomly breaking public APIs is not an option.

- **Create feature branches** - Don't ask us to pull from your master branch.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](http://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.


## Every place that documents behaviour

A change to what the package does is not finished until each surface below says the same thing.
Some are gated, some are not, and the ones that are not are where drift has actually happened.

| Surface | When it changes | Gate |
|---|---|---|
| **`README.md`** | any public API, and anything a new user should know | `tests/ArchTest.php` fails when a facade method is missing from it |
| **`UPGRADE.md`** | anything a consumer will notice, under `## Unreleased` | none: review |
| **`docs/decisions/`** | a decision changes, or a record's outcome turns out differently | `tests/SpecTest.php` checks references resolve |
| **`docs/spec/invariants.md`** | a rule that breaks the product when violated | as above |
| **`docs/spec/conventions.md`** | how code here is written | as above |
| **`docs/spec/public-api.md`** | the exposed surface | as above |
| **`docs/spec/quality-policy.md`** | a gate moves, or a floor does | as above |
| **`ARCHITECTURE.md`** | the shape of the package, since it is the index | none: review |
| **`CLAUDE.md`** | anything an agent working here has to know before touching `src/` | none: review |
| **Class docblocks** | the class stops doing what its docblock says | two mechanical rules in `tests/ArchTest.php` |
| **`samples/`** | anything under `src/Signing/`, regenerated with `poc/sign-samples.php` | `tests/SamplesTest.php` |
| **The `docs` branch** | any public behaviour, once it is released | **none, and it is a separate pull request** |
| **The release notes** | every tag, on GitHub and in the site's `release-notes.md` | none: review |

### The `docs` branch is the one that gets forgotten

The documentation site at [laravel-a1-pdf-sign.netlify.app](https://laravel-a1-pdf-sign.netlify.app)
lives on the `docs` branch, not on `main`, so nothing in a `main` pull request can check it and no
test on `main` will ever fail because of it. It has gone stale twice.

Treat it as part of shipping rather than as follow-up: when a release goes out, the same day it goes
out, open a pull request against `docs` adding the release notes entry and updating the reference
pages the change touches.

**Do not document unreleased behaviour there.** The site describes what is installable, so a feature
merged to `main` and not yet tagged does not belong on it. That is the one reason the branch is
allowed to lag, and it lags until the tag rather than after it.

## Running the checks

``` bash
$ composer check       # style, static analysis and tests, the same as CI
$ composer test        # tests only
$ composer lint        # fix code style
$ composer analyse     # static analysis only
$ composer test:types  # type coverage across src/
$ composer test:cov    # line coverage (needs pcov or xdebug)
$ composer test:mutate # mutation testing (slow: runs nightly in CI, not on PRs)
```

Tests are written with [Pest](https://pestphp.com). `tests/ArchTest.php` holds
architectural rules that run with the rest of the suite. Tests in the `network` group reach a
live timestamp authority and fail without internet; skip them with
`vendor/bin/pest --exclude-group=network`.

Helpers shared between test files belong in `tests/Pest.php`. Defined anywhere else they are
invisible to the other files once the suite runs with `--parallel`.

### The Git hooks

[Husky](https://typicode.github.io/husky/) installs two:

``` bash
$ npm install     # once, to install them
```

| Hook | Does |
|---|---|
| `pre-commit` | runs Pint over the staged PHP files and stages the result, then PHPStan, so neither style nor static analysis is what a pull request gets blocked on |
| `pre-push` | refuses a push that lands on `main`. Tags are unaffected, so publishing a release still works |

Node is only used for the hooks: it is not a dependency of the package. Skipping `npm install`
costs you the formatting convenience **and the `main` guard**, so install them. If your
machine runs a PHP older than Pint requires, `pre-commit` detects it and routes through the
Docker service described below.

Both hooks can be bypassed with `--no-verify`. That is deliberate, for stashing work in
progress; on `pre-push` it means you are choosing to push to `main` and saying so.

### The verification tools never ship

`veraPDF`, `qpdf`, `pyHanko`, poppler's `pdfsig` and `pdftoppm`, and Ghostscript are
**development and validation instruments only**. Nothing in `src/` may invoke one, and nothing built for
testing reaches the package a consumer installs: an architectural test enforces the first
and `tests/DistributionTest.php` asks `git archive` what a release actually contains.

`qpdf` is in the development image and its checks run with the rest of the suite. It is
strict where poppler forgives, which is the point: a cross-reference table with slightly
wrong offsets still opens in a reader that recovers by scanning.

### Checking PDF/A conformance

`veraPDF` is the reference validator and the only thing that can establish a conformance
verdict. It is Java and it is installed in the development image, so it runs with the rest
of the suite:

``` bash
$ docker compose -f .docker/compose.yaml run --rm php vendor/bin/pest --group=pdfa
```

**No test is allowed to skip.** `composer test` carries `--fail-on-skipped`: every check
has to run somewhere, and a skip is how one quietly stops. If you run the suite outside
the container and veraPDF is not installed, that group will fail rather than pass silently.

It is a **development and CI instrument only**: nothing in `src/` may invoke veraPDF,
`qpdf`, `pyHanko`, `pdfsig`, `pdftoppm` or Ghostscript, and an architectural test fails if
it does.

### Checking the output in a real reader

Our validator shares its assumptions with the code it validates, so a green
suite is not proof that Adobe Reader agrees. `samples/` holds one signed PDF per
profile plus a six-signature document, with instructions for regenerating them
and for reading what each one should show. **Regenerate and re-check them after
any change to `src/Signing/`**. Poppler's `pdfsig` has caught bugs the suite
passed straight through.

The package targets PHP 8.4+ and Laravel 13. If your machine runs an older PHP,
`.docker/` reproduces any cell of the CI matrix:

``` bash
$ docker compose -f .docker/compose.yaml run --rm php composer check
```

### Keeping your IDE in sync

Each Docker service keeps `vendor/` in its own named volume, so switching PHP
versions does not clobber the other install. The trade-off is that the
`vendor/` your editor indexes, the one on your machine, is never touched by
those runs, and it goes stale as dependencies change. Classes then show up as
"not found" even though the suite is green.

Refresh it after any dependency change:

``` bash
$ composer install --ignore-platform-reqs
```

`--ignore-platform-reqs` is what lets this work on a host running an older PHP
than the package requires: the files land for indexing, and nothing executes
them there.


**Happy coding**!
