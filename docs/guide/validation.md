# Validation

```php
$report = A1PdfSign::validate($signedPdf);

$report->isValid();                  // every signature verifies
$report->signatures;                 // one entry each

foreach ($report->signatures as $signature) {
    $signature->verified;            // the CMS verifies against the bytes
    $signature->coversWholeDocument;
    $signature->signer()?->commonName;
    $signature->isTimestamp;
}
```

**"Valid" means the CMS actually verifies**, not that a subject could be
parsed. Document timestamps are classified separately and excluded from
`isValid()`: they are timestamps over the file, not signatures by a signer.

It reads documents this package did not write. What each field means, and what
is checked to produce it, is
[signet-pdf's validation reference](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/spec/public-api.md).

## From a disk, and from an encrypted document

```php
A1PdfSign::validate(A1PdfSign::fromDisk('s3', 'contracts/deal-signed.pdf'));
A1PdfSign::validate($path, trust: null, documentPassword: 'the document password');
```

## Against a trust store

```php
use LSNepomuceno\Signet\Validation\TrustStore;

A1PdfSign::validate($pdf, TrustStore::fromDirectory('/etc/ssl/certs'));
```

Whom to trust is the application's policy, not the package's, which is why
nothing is trusted by default.

## It needs the openssl binary

Verification shells out. On a host without the binary it raises rather than
reporting every signature as invalid, which is what it used to do: a missing
binary is not a verdict.

`php artisan a1-pdf-sign:check` answers that before it matters, and
`php artisan pdf:validate-signature signed.pdf` is the same report from a
pipeline.
