# Configuration

```bash
php artisan vendor:publish --tag=a1-pdf-sign-config
```

Every key is a scalar and every one has an env variable or a sensible default.
`Config\SignetConfigFactory` turns them into the engine's own configuration
when the container boots, which is where a bad value fails:

```
a1-pdf-sign.signature.profile is [pades-b-xx], and has to be one of:
legacy, pades-b-b, pades-b-t, pades-b-lt, pades-b-lta
```

**The file holds no objects**, so `config:cache` keeps working. That is an
invariant rather than a detail: a config file carrying an enum instance fails to
cache, and it fails in your application rather than here, on a command nobody
runs until deployment.

## Signing

| Key | Env | Default |
|---|---|---|
| `signature.profile` | `A1_PDF_SIGN_PROFILE` | `pades-b-b` |
| `signature.digest_algorithm` | `A1_PDF_SIGN_DIGEST` | `sha256` |
| `signature.policy` | `A1_PDF_SIGN_POLICY` | none |

`profile` is what a signature is: `legacy`, `pades-b-b`, `pades-b-t`,
`pades-b-lt`, `pades-b-lta`. What each requires and what it buys is
[signet-pdf's profile reference](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/guide/profiles.md).

`policy` declares an ICP-Brasil signature policy. Naming a family resolves to
the version **in force**, which is the point of naming a family at all:

```php
'policy' => 'ad-rt',                      // whichever AD-RT is current
'policy' => '2.16.76.1.7.1.12.1.2',       // that version, specifically
'policy' => [                             // a policy from anywhere else
    'oid' => '…',
    'digest_algorithm' => 'sha256',
    'digest' => '…',
    'uri' => 'https://…',
],
```

## The timestamp authority

| Key | Env | Default |
|---|---|---|
| `signature.timestamp.url` | `A1_TSA_URL` | none |
| `signature.timestamp.username` | `A1_TSA_USERNAME` | none |
| `signature.timestamp.password` | `A1_TSA_PASSWORD` | none |
| `signature.timestamp.timeout` | | 20 |
| `signature.timestamp.attempts` | | 3 |
| `signature.timestamp.backoff` | | 200 |

Required by every profile above `pades-b-b`. Signing refuses rather than
silently producing a lower profile than you asked for.

`signature.ltv.*` carries the same three numbers for revocation material, and
they are deliberately smaller: **a timestamp authority failing fails the
signature, and a revocation responder failing only means less material is
embedded.**

## Certificates

| Key | Env | Default |
|---|---|---|
| `certificate.use_path_env` | `A1_PDF_SIGN_USE_PATH_ENV` | `false` |
| `certificate.legacy` | `A1_PDF_SIGN_LEGACY_CERTIFICATE` | `false` |
| `certificate.chain_paths` | | `[]` |

`legacy` adds openssl's `-legacy` flag, needed to read old PFX files under
OpenSSL 3. `chain_paths` is a **set** of intermediate certificates, not a
sequence: the engine builds the chain from what the files contain rather than
trusting the order they are listed in.

## The seal

`seal.driver`, `seal.transparent`, `seal.background`, `seal.text.x`,
`seal.text.rows`, `seal.font.path`, `seal.font.size`, `seal.font.color`.

What each does to the rendered image is
[signet-pdf's seal reference](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/guide/seals.md).

## Temporary files

`temp_path`, `A1_PDF_SIGN_TEMP_PATH`. Null uses the system temporary directory.
`A1PdfSign::tempPath()` creates it if it is not there, which is what a queued
job on a fresh container needs.
