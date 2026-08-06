<p align="center">
  <img src="https://user-images.githubusercontent.com/14093492/127516361-48fbde85-1f34-4626-82ae-44b11aa0de15.png" alt="Signature image">
</p>

<h1 align="center">Sign PDF files with valid x509 certificate</h1>

<p align="center">
  <a href="https://github.com/lsnepomuceno/laravel-a1-pdf-sign/releases/latest">
    <img src="https://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/v" alt="Latest Stable Version">
  </a>
  <a href="https://packagist.org/packages/lsnepomuceno/laravel-a1-pdf-sign/stats">
    <img src="https://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/downloads" alt="Total Downloads">
  </a>
  <a href="https://github.com/lsnepomuceno/laravel-a1-pdf-sign/tree/dev">
    <img src="https://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/v/unstable" alt="Latest Unstable Version">
  </a>
  <a href="https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/LICENSE.md">
    <img src="https://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/license" alt="License">
  </a>
  <a href="https://github.com/lsnepomuceno/laravel-a1-pdf-sign/actions/workflows/main_action.yml">
    <img src="https://github.com/lsnepomuceno/laravel-a1-pdf-sign/actions/workflows/main_action.yml/badge.svg?branch=main" alt="Tests">
  </a>
</p>

<table align="center">
  <thead>
    <tr>
      <th colspan="4">Reference</th>
    </tr>
  </thead>
  <tr>
    <td>Laravel version</td>
    <td>PHP version</td>
    <td>Package version</td>
    <td>Docs</td>
  </tr>

  <tr>
    <td>^8 ~8.54</td>
    <td rowspan="2">^7.4</td>
    <td>^0 ~0.0.11</td>
    <td rowspan="2"><a href="https://laravel-a1-pdf-sign.netlify.app/docs/0.x/home">Official Doc</a></td>
  </tr>

  <tr>
    <td>^8.56+</td>
    <td>^0.0.12</td>
  </tr>

  <tr>
    <td>^9</td>
    <td>^8.1 || ^8.2</td>
    <td rowspan="3">^1</td>
    <td rowspan="3"><a href="https://laravel-a1-pdf-sign.netlify.app/docs/1.x/release-notes">Official Doc</a></td>
  </tr>

  <tr>
    <td>^10</td>
    <td>^8.1 || ^8.2 || ^8.3</td>
  </tr>

  <tr>
    <td>^11 || ^12</td>
    <td>^8.2 || ^8.3 || ^8.4</td>
  </tr>

  <tr>
    <td>^13</td>
    <td>^8.4 || ^8.5</td>
    <td>^2</td>
    <td><a href="https://laravel-a1-pdf-sign.netlify.app/docs/1.x/release-notes">Official Doc</a></td>
  </tr>
</table>

## Version 2

```bash
composer require lsnepomuceno/laravel-a1-pdf-sign
```

```php
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signed = A1PdfSign::newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($pdfPath)
    ->info(name: 'Lucas', reason: 'Contract')
    ->seal()                       // omit for an invisible signature
    ->sign();

$signed->contents();               // string
$signed->save($path);              // path
$signed->download('contract.pdf'); // BinaryFileResponse
```

**Signing appends a revision rather than rebuilding the document.** The original bytes survive, so annotations and form
fields are preserved and a document can carry more than one signature — the request in
[TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430), open since 2021.

### PAdES profiles

| Profile       | Adds                                                                               |
|---------------|------------------------------------------------------------------------------------|
| `legacy`      | ISO 32000-1 detached CMS                                                           |
| `pades-b-b`   | CAdES signed attributes, with ESS `signing-certificate-v2`. **Default**            |
| `pades-b-t`   | plus an RFC 3161 timestamp                                                         |
| `pades-b-lt`  | plus a Document Security Store, so it still verifies after the certificate expires |
| `pades-b-lta` | plus an archive timestamp over the whole file                                      |

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->profile('pades-b-lt')   // needs A1_TSA_URL configured
    ->sign();
```

### Validation

```php
$report = A1PdfSign::validate($pdfPath);

$report->isValid();     // every signature verifies against the bytes it covers
$report->count();       // how many signatures the document carries
$report->signers();     // structured signer identity
```

`isValid()` answers whether each signature matches the document. It does not check the issuer against a trust store —
that decision stays with your application.

Configuration is publishable:

```bash
php artisan vendor:publish --tag=a1-pdf-sign-config
```

Upgrading from 1.x? See [UPGRADE.md](UPGRADE.md).
