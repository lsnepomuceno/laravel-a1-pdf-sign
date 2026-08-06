# Contributing

Contributions are **welcome** and will be fully **credited**.

We accept contributions via Pull Requests on [Github](https://github.com/lsnepomuceno/laravel-a1-pdf-sign).


## Pull Requests

- **Code style is [PER-CS](https://www.php-fig.org/per/coding-style/)**, enforced by [Pint](https://laravel.com/docs/pint). Run `composer lint` to apply it; CI runs `vendor/bin/pint --test` and fails on any difference.

- **Static analysis must stay clean** - PHPStan runs at `level: max`. Pre-existing violations live in `phpstan-baseline.neon` and are being driven to zero; new code must not add to it. Run `composer analyse`.

- **Add tests!** - Your patch won't be accepted if it doesn't have tests.

- **Document any change in behaviour** - Make sure the `README.md` and any other relevant documentation are kept up-to-date.

- **Consider our release cycle** - We try to follow [SemVer v2.0.0](http://semver.org/). Randomly breaking public APIs is not an option.

- **Create feature branches** - Don't ask us to pull from your master branch.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](http://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.


## Running the checks

``` bash
$ composer check     # style, static analysis and tests — the same as CI
$ composer test      # tests only
$ composer lint      # fix code style
$ composer analyse   # static analysis only
```

The package targets PHP 8.4+ and Laravel 12+. If your machine runs an older PHP,
`.docker/` reproduces any cell of the CI matrix:

``` bash
$ docker compose -f .docker/compose.yaml run --rm php composer check
```


**Happy coding**!
