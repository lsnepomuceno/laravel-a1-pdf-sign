# Decisions

One file per decision, numbered. The number is the identifier: it is what code
cites, so it never changes and is never reused.

Each records the context that forced a choice, the alternatives weighed, and an
outcome section when what shipped differed from what was decided. That last part
is the point: a decision record whose outcome is never written back is how a
document drifts away from the code it describes.

| # | Decision |
|---|---|
| [0001](0001-openssl-native-with-cli-fallback.md) | Read certificates through `ext-openssl`, keep the CLI as a fallback |
| [0002](0002-asn1-parsed-in-package.md) | Parse the CMS in-package, by declared length |
| [0003](0003-temporary-files-outside-the-package.md) | Temporary files live outside the package, with guaranteed cleanup |
| [0004](0004-in-memory-seal.md) | The seal is rendered in memory |
| [0005](0005-php-and-laravel-floor.md) | PHP 8.4 and Laravel 13 as the floor |
| [0006](0006-incremental-revision.md) | Sign by appending a revision, written in-package |
| [0007](0007-pem-second-entry-one-pipeline.md) | PEM as a second entry point onto one pipeline |

Rules that break the product when violated are not decisions and do not live
here. They are in [the invariants](../spec/invariants.md).
