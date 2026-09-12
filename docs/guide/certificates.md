# Certificates

## Signing with one

```php
A1PdfSign::signFromFile($pfxPath, $password, $pdfPath);        // PKCS#12
A1PdfSign::signFromPem($pemPath, $password, $pdfPath, $key);   // PEM
A1PdfSign::signFromUpload($request->file('cert'), $password, $pdfPath);
```

The third takes `Illuminate\Http\UploadedFile`, which is the entry point
signet-pdf cannot offer.

For anything beyond one signature with no seal and no profile override, use the
builder.

## Storing one

A certificate and its password are sealed together, and the key that opens them
comes back separately:

```php
$sealed = A1PdfSign::encryptCertificate($pfxPath, $password);

$sealed->certificate;   // store this
$sealed->password;      // and this
$sealed->hash;          // and this somewhere else
```

Reading it back takes all three, and **the third argument is the sealed
password, not the plaintext one**:

```php
$certificate = A1PdfSign::decryptCertificate($sealed->hash, $sealed->certificate, $sealed->password);
```

### What seals it, and why it matters

The vault uses **Laravel's own encrypter**, AES-128-CBC under a 16-byte key
generated per certificate. Two consequences worth knowing:

- **Material sealed by 2.x opens unchanged.** Upgrading re-encrypts nothing.
- **The key is per certificate, not `APP_KEY`.** Rotating the application key
  does not orphan stored bundles, and losing the per-certificate key does
  orphan that one. Store it where you would store any other secret.

signet-pdf's own vault defaults to XChaCha20-Poly1305 under a 32-byte key, and
reads both envelopes keyed off the key's length, so material sealed here opens
there too ([0038](/decisions/0038-the-envelope-is-versioned)).

## Legacy bundles

A PFX produced years ago may use ciphers OpenSSL 3 refuses by default.
`certificate.legacy` adds the `-legacy` flag, and that path shells out to the
`openssl` binary rather than using `ext-openssl`, which is why
`a1-pdf-sign:check` reports whether the binary is there.

## Who the signer is

```php
$report = A1PdfSign::icpBrasil($pfxPath, $password);

$report->identity->cpf;
$report->identity->name;
$report->findings;
```

What the fields mean, and what the certificate has to carry for each to be
populated, is
[signet-pdf's ICP-Brasil reference](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/guide/icp-brasil.md).
