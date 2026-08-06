# Contributing

Contributions are **welcome** and will be fully **credited**.

We accept contributions via Pull Requests on [Github](https://github.com/lsnepomuceno/laravel-a1-pdf-sign).


## Pull Requests

- **Code style is [PER-CS](https://www.php-fig.org/per/coding-style/)**, enforced by [Pint](https://laravel.com/docs/pint). Run `composer lint` to apply it; CI runs `vendor/bin/pint --test` and fails on any difference.

- **Static analysis must stay clean** - PHPStan runs at `level: max` with no baseline, so the gate is "no errors", not "no new errors". Run `composer analyse`.

- **Add tests!** - Your patch won't be accepted if it doesn't have tests.

- **Document any change in behaviour** - Make sure the `README.md` and any other relevant documentation are kept up-to-date.

- **Consider our release cycle** - We try to follow [SemVer v2.0.0](http://semver.org/). Randomly breaking public APIs is not an option.

- **Create feature branches** - Don't ask us to pull from your master branch.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](http://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.


## Running the checks

``` bash
$ composer check       # style, static analysis and tests — the same as CI
$ composer test        # tests only
$ composer lint        # fix code style
$ composer analyse     # static analysis only
$ composer test:types  # type coverage across src/
$ composer test:cov    # line coverage (needs pcov or xdebug)
$ composer test:mutate # mutation testing (slow — runs nightly in CI, not on PRs)
```

Tests are written with [Pest](https://pestphp.com). `tests/ArchTest.php` holds
architectural rules that run with the rest of the suite. Tests in the `network` group reach a
live timestamp authority and fail without internet; skip them with
`vendor/bin/pest --exclude-group=network`.

Helpers shared between test files belong in `tests/Pest.php`. Defined anywhere else they are
invisible to the other files once the suite runs with `--parallel`.

### Formatting on commit

A [Husky](https://typicode.github.io/husky/) `pre-commit` hook runs Pint over the staged PHP
files and stages the result, so style is never what a pull request gets blocked on:

``` bash
$ npm install     # once, to install the hook
```

Node is only used for the hook — it is not a dependency of the package, and skipping this
step costs you nothing but the convenience. If your machine runs a PHP older than Pint
requires, the hook detects it and routes through the Docker service described below.

### Checking the output in a real reader

Our validator shares its assumptions with the code it validates, so a green
suite is not proof that Adobe Reader agrees. `samples/` holds one signed PDF per
profile plus a six-signature document, with instructions for regenerating them
and for reading what each one should show. **Regenerate and re-check them after
any change to `src/Signing/`** — poppler's `pdfsig` has caught bugs the suite
passed straight through.

The package targets PHP 8.4+ and Laravel 13. If your machine runs an older PHP,
`.docker/` reproduces any cell of the CI matrix:

``` bash
$ docker compose -f .docker/compose.yaml run --rm php composer check
```

### Keeping your IDE in sync

Each Docker service keeps `vendor/` in its own named volume, so switching PHP
versions does not clobber the other install. The trade-off is that the
`vendor/` your editor indexes — the one on your machine — is never touched by
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
