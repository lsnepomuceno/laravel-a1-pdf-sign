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
