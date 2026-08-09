# 0007: PEM as a second entry point onto one pipeline

**Status:** accepted, implemented in 2.1.0.
Answers [discussion #147](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/147),
open since 2024-07.

## Context

The package accepted PKCS#12 and nothing else. Every public entry funnelled into
`CertificateReader::read()`, whose implementations called `openssl_pkcs12_read()`
or `openssl pkcs12`. The contract's own docblock said "the raw bytes of a
.pfx / .p12 file". The only answer available to a user holding a `.pem` was
"convert it with OpenSSL first".

### The pipeline is already PEM

That closed door hid how little had to change. **PKCS#12 is not a peer of PEM,
it is a container that gets converted into PEM** before anything else runs. PEM
is the destination format, not a sibling:

| Component | What it already handled |
|---|---|
| `Data\Certificate::$original` | the combined certificate and key, in PEM |
| `CertificateParser::parse()` | takes a PEM bundle and a password |
| `CertificateVault::open()` | reparses the stored PEM directly |
| `Cades\CadesBuilder` | extracts the chain with a PEM regex |

A caller could already reach this by hand, `parse($pem, $pw)` into
`usingCertificate()`, an undocumented back door, and a broken one.

### The defect this uncovered

`CertificateParser` passed the bundle to `openssl_x509_check_private_key()` as a
**string**. That form cannot decrypt a passphrase-protected key, which is what a
real `.pem` usually carries. Measured against ext-openssl:

| Call | Result |
|---|---|
| `check_private_key($x509, $pem)`, encrypted key | **FAIL** |
| `check_private_key($x509, [$pem, $password])`, encrypted key | OK |
| `check_private_key($x509, [$pem, 'wrong'])` | FAIL, as it must |
| `check_private_key($x509, [$pem, $password])`, unencrypted key | OK |
| `x509_read()` with the key written before the certificate | OK, order is irrelevant |
| `x509_read()` on DER bytes | FAIL, detectable, so reportable as a format error |

The array form is correct for encrypted and unencrypted keys alike, so the fix
is uniform and needs no branch. PKCS#12 never exposed the bug because
`openssl_pkcs12_read()` hands back an already-decrypted key.

## Decision

**Diverge at the entry, converge at the reader.** A second entry point, not a
second pipeline.

`Certificates\PemCertificateReader` implements the existing `CertificateReader`
as its degenerate case, the reader whose conversion step is empty. Everything
downstream of the parsed certificate is untouched.

A parallel contract, DTO and pipeline was rejected: it would fork `CadesBuilder`,
`CertificateVault`, `SealRenderer` and the public `Data\*` shape to gain nothing.

Divergence is confined to where it is real:

- **PEM may arrive as two files**, so `certificatePem($cert, $key, $password)`
  and `certificateFromPem($contents, $key, $password)` take an optional key.
- **A PEM private key is often unencrypted**, which PKCS#12 cannot express, so
  the password defaults to empty. OpenSSL ignores a passphrase given for a key
  that does not need one.
- **Nothing gates on the file extension.** PEM ships as `.pem`, `.crt`, `.cer`,
  `.key` and `.txt`, so the encoding is read from the content, and
  `PemCertificateReader` owns that decision so the command, the vault and the
  reader cannot drift apart.

## Consequences

- The contract's parameter became `$contents`, since PKCS#12 is no longer the
  only encoding it reads. Every call site in the package is positional.
- `signFromPem()` joins the `A1PdfSign` contract, a breaking change for anyone
  implementing it, mapped in `UPGRADE.md`.
- `encryptCertificate()` detects the encoding rather than gaining a sibling: it
  takes "a certificate" generically, where signing keeps explicit entry points.
- `pdf:sign` routes by content and takes `--key`; passing it with a PKCS#12
  bundle is rejected rather than ignored.
- `InvalidPemContentException` covers only what is decidable before parsing. A
  certificate and key that are both valid but unrelated keep raising
  `InvalidX509PrivateKeyException`, whose message already says exactly that.
- `.gitignore` covers `*.pem` and `*.key`. Unlike a `.pfx`, a PEM private key is
  often unencrypted.
