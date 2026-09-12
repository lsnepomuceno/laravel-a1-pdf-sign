# Getting started

```bash
composer require lsnepomuceno/laravel-a1-pdf-sign
```

Nothing to register: the service provider is discovered and the `A1PdfSign`
facade is available immediately.

## Requirements

| | |
|---|---|
| PHP | 8.4.1 – 8.5 |
| Laravel | 13 |
| Extensions | `ext-json`, plus what signet-pdf requires: `openssl`, `mbstring`, `gd`, `zlib`, `sodium`, `fileinfo` |
| `openssl` on `PATH` | for validation, and for reading legacy PFX bundles under OpenSSL 3 |

`php artisan a1-pdf-sign:check` answers the last one, and everything else about
the environment, before anything is signed.

## Sign something

```php
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signed = A1PdfSign::newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($contractPath)
    ->sign();

$signed->save(storage_path('app/contract-signed.pdf'));
```

The builder is signet-pdf's `PendingSignature`, so
[its reference](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/guide/signing.md)
is the full list of what it takes: seals, profiles, certification, field locks,
a signer's name and reason.

## Where things live

| You want | Look |
|---|---|
| a config key | [Configuration](/guide/configuration) |
| to sign from S3, or from an upload | [Disks and uploads](/guide/disks) |
| to store a certificate | [Certificates](/guide/certificates) |
| to fill a field somebody else placed | [Templates](/guide/templates) |
| to check a document that is already signed | [Validation](/guide/validation) |
| to test an application that signs | [Testing](/guide/testing) |
| what a profile means, how a seal is drawn, what ICP-Brasil requires | [signet-pdf](https://github.com/lsnepomuceno/signet-pdf) |
