# Tests

#### Run the tests with:
```Shell
composer test
```

#### Everything CI runs, style, static analysis, dependencies and the suite:
```Shell
composer check
```

#### Other scripts:
```Shell
composer lint        # fix code style with Pint
composer analyse     # PHPStan at level max, no baseline
composer deps        # unused and shadow dependency report
composer test:cov    # line coverage (needs pcov or xdebug)
composer test:types  # type coverage, gated at 100%
composer test:mutate # mutation testing (slow; runs nightly in CI)
```

The suite runs on Orchestra Testbench, not a host application, and the `openssl` binary is **not** required to run it: throwaway PKCS#12 bundles are generated through `ext-openssl`.

Tests in the `network` group reach a live timestamp authority and fail without internet:

```Shell
vendor/bin/pest --exclude-group=network
```

<hr>

#### Nothing skips. <small>(since 2.4)</small>

`composer test` carries `--fail-on-skipped`. Every check has to run somewhere, and a skip is how one quietly stops running: the PDF/A group spent a release skipping itself by default, which left the conformance claims unverified on the machine where the work was happening.

The suite therefore expects two tools present, both installed in the project's Docker image:

| | |
|---|---|
| **veraPDF** | the reference PDF/A validator. It decides the conformance verdicts, and it blocks |
| **qpdf** | structural check, applied comparatively: signing must not introduce a complaint the input did not already have |

Both are **development and CI instruments only**. Nothing in `src/` may invoke one, and the architecture tests fail if it does: a package that shelled out to a JVM to answer a runtime question would be a different package, and consuming applications would inherit an installation requirement nobody wrote down.

```Shell
docker compose -f .docker/compose.yaml run --rm php composer check
```

<hr>

#### What the `network` group is still for. <small>(since 2.4)</small>

The timestamped profiles used to be testable only against a live authority, which meant `pades-b-t`, `pades-b-lt` and `pades-b-lta` could regress without CI going red. `Contracts\SignatureTransport` made the transport substitutable, and the suite now answers with real RFC 3161 tokens locally, so those levels are checked on every run.

What stays in the `network` group is the question the local one cannot answer: **whether this package interoperates with somebody else's authority.** Those tests are reported rather than blocking, because an outage on a third party's side is not a defect here.
