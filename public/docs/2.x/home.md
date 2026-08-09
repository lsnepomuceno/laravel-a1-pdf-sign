# Welcome to the lsnepomuceno/laravel-a1-pdf-sign wiki!
### Use this documentation for feature implementation.

Sign PDF files with A1/x509 certificates, PKCS#12 (`.pfx` / `.p12`) or PEM, and cryptographically verify signatures already in a document.

```PHP
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->sign();

$signed->save('path/to/signed.pdf');
```

**Signing appends a revision rather than rebuilding the document.** The original bytes survive, so annotations and form fields are preserved and a document can carry more than one signature.

### Fast links
* [Release notes](/docs/2.x/release-notes)
* [Upgrading from 1.x](/docs/2.x/upgrade-from-1x)
* [Installation](/docs/2.x/installation)
* [Usage](/docs/2.x/usage)
* [Signature profiles](/docs/2.x/signature-profiles)
* [Tests](/docs/2.x/tests)
