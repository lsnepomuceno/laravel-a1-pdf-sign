# Commands

Six, and their names and exit codes are public API: a pipeline calls them.

```bash
php artisan a1-pdf-sign:check

php artisan pdf:sign contract.pdf certificate.pfx "password" signed.pdf
php artisan pdf:sign contract.pdf certificate.pem "" signed.pdf --key=private.key
php artisan pdf:validate-signature signed.pdf

php artisan pdf:fields template.pdf
php artisan pdf:add-field template.pdf Manager placed.pdf --x=60 --y=400 --width=120 --height=40
php artisan pdf:extend archived.pdf
```

Every one exits `0` on success and `1` on failure, with the reason on stderr.
Nothing raises out of a command: a stack trace is not a useful thing for a
pipeline to receive.

## `a1-pdf-sign:check`

Answers whether this environment can sign and validate **before** anything is
signed: the extensions, the `openssl` binary, the temporary directory, the
configured profile and whether it needs an authority.

It reaches no network unless asked. A diagnostic that contacted a third party
by default would make every artisan call do it too.

## `pdf:extend`

Appends a fresh archive timestamp to a B-LTA document, renewing it before the
existing one ages out. **It is the one operation here that a scheduler calls
rather than a request**, which is the argument for it being on the command line
at all:

```php
Schedule::command('pdf:extend', [$path])->monthly();
```

## `pdf:sign` routes on content, not on extension

PEM ships as `.pem`, `.crt`, `.key` and more, so the certificate's encoding
decides which entry point is used rather than its suffix. `--key` alongside a
PKCS#12 bundle is refused rather than ignored.
