<h1 align="center">Sign PDF files with an A1 certificate</h1>

<p align="center">
  Digital signatures for Laravel, from PKCS#12 or PEM, with PAdES profiles, long-term validation
  <br>and cryptographic verification of signatures a document already carries.
</p>

<p align="center">
  <a href="https://packagist.org/packages/lsnepomuceno/laravel-a1-pdf-sign"><img alt="Latest version" src="https://img.shields.io/packagist/v/lsnepomuceno/laravel-a1-pdf-sign?style=flat-square&color=1f7a3d&label=packagist"></a>
  <a href="https://packagist.org/packages/lsnepomuceno/laravel-a1-pdf-sign/stats"><img alt="Downloads" src="https://img.shields.io/packagist/dt/lsnepomuceno/laravel-a1-pdf-sign?style=flat-square&color=1f7a3d"></a>
  <a href="https://github.com/lsnepomuceno/laravel-a1-pdf-sign/actions/workflows/main_action.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/lsnepomuceno/laravel-a1-pdf-sign/main_action.yml?branch=main&style=flat-square&label=tests"></a>
  <a href="https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/LICENSE.md"><img alt="License" src="https://img.shields.io/packagist/l/lsnepomuceno/laravel-a1-pdf-sign?style=flat-square&color=555"></a>
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/php-8.4%20%E2%80%93%208.5-777bb4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel" src="https://img.shields.io/badge/laravel-13-ff2d20?style=flat-square&logo=laravel&logoColor=white">
  <img alt="PHPStan" src="https://img.shields.io/badge/phpstan-level%20max-2a2a2a?style=flat-square">
  <img alt="Type coverage" src="https://img.shields.io/badge/type%20coverage-100%25-1f7a3d?style=flat-square">
</p>

<p align="center">
  <a href="https://laravel-a1-pdf-sign.netlify.app/docs/2.x/home"><b>Documentation</b></a>
  &nbsp;·&nbsp;
  <a href="https://laravel-a1-pdf-sign.netlify.app/docs/2.x/release-notes">Release notes</a>
  &nbsp;·&nbsp;
  <a href="UPGRADE.md">Upgrading</a>
  &nbsp;·&nbsp;
  <a href="samples/README.md">Signed samples</a>
</p>

---

## Installation

```bash
composer require lsnepomuceno/laravel-a1-pdf-sign
```

Nothing else to register: the service provider is discovered, and the `A1PdfSign` facade is available immediately.

`openssl` on `PATH` is **not** required to sign; it is used only for verifying a signature and for reading a legacy
PFX file. Where it is needed it is needed properly: **`ext-openssl` being loaded is a different thing from the binary
being installed**, and a minimal container commonly has the first without the second. Validating without it raises
`MissingBinaryException`, and an environment where `proc_open` is disabled raises `ProcessUnavailableException`.
Neither is reported as a signature that failed to verify.

Every exception this package raises implements `Exceptions\A1PdfSignException`, so an application can handle them
as a group rather than by name:

```php
use LSNepomuceno\LaravelA1PdfSign\Exceptions\A1PdfSignException;

$exceptions->report(function (A1PdfSignException $e) { … });
```

The classes stay granular beneath it. `InvalidCertificatePasswordException` is the one worth catching on its own,
since a wrong password is the failure a production application meets most, and it extends
`InvalidCertificateContentException` so the general catch still works.

```bash
php artisan vendor:publish --tag=a1-pdf-sign-config
```

## Signing

```php
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signed = A1PdfSign::newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($pdfPath)
    ->info(name: 'Lucas', reason: 'Contract')
    ->seal()                       // omit for an invisible signature
    ->sign();

$signed->contents;                 // string
$signed->save($path);              // path
$signed->download('contract.pdf'); // BinaryFileResponse
```

> [!IMPORTANT]
> **Signing appends a revision rather than rebuilding the document.** The original bytes survive byte for byte, so
> annotations, form fields and every earlier signature are preserved, and a document can carry as many signatures as it
> needs. That is [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430), open since 2021, and it is the single most
> important behaviour in this package.

### The document does not have to be a local file

An application keeping contracts on `s3`, `minio` or any Flysystem disk does not have to download one first:

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdfFromDisk('s3', 'contracts/deal.pdf')
    ->sign();

// or hand over the bytes yourself, from anywhere at all
->pdfContents($bytes, 'deal.pdf')
```

Both hold the whole document in memory, so neither helps with a very large one.

[Signing a document →](https://laravel-a1-pdf-sign.netlify.app/docs/2.x/sign-pdf-file)

## What it does

| | |
|---|---|
| **PKCS#12 and PEM** | `.pfx`, `.p12`, or a PEM certificate with the key beside it or in its own file |
| **PAdES profiles** | `legacy` through `pades-b-lta`, with RFC 3161 timestamps and long-term validation |
| **Visible seals** | rendered from the certificate or drawn from your own artwork, on the page you name |
| **Template fields** | fills a signature field a contract already carries, instead of appending beside it |
| **Certification** | ISO 32000-1 §12.8.2.2 DocMDP, plus field locks that later signatures honour |
| **Encrypted documents** | AES-128 and AES-256, signed and re-encrypted under the document's own key |
| **Archive maintenance** | refresh a B-LTA archive with no certificate and no key material involved |
| **Verification** | the CMS is actually verified, with the timestamp, the profile and revocation reported |
| **ICP-Brasil identity** | CPF, CNPJ and the rest, read from the certificate rather than parsed out of a name |
| **PDF/A** | a signed document stays conformant, measured with veraPDF rather than assumed |
| **PDF/UA** | measured too: an invisible signature keeps an accessible document conformant, a visible seal does not |

## Bringing your own seal

The seal is drawn by `Contracts\SealRenderer`, bound in the container, so replacing it is one line in your own
service provider:

```php
$this->app->bind(SealRenderer::class, QrCodeSealRenderer::class);
```

That is the route for a corporate logo, a QR code linking to a validation page, or any layout of your own. The
contract has two methods: `render()` builds a seal from the certificate, and `fromImage()` embeds artwork you
already have.

**`fromImage()` is also the answer to "can I draw the seal with Blade".** Render your view to an image however you
like and hand the result over; the package stays out of the business of turning HTML into pixels, which would be a
large dependency for a signing library.

## Certificates

```php
// PKCS#12
A1PdfSign::newSignature()->certificate($pfxPath, $password);

// PEM, key in the same file or in its own
A1PdfSign::newSignature()->certificatePem($certificatePath, $keyPath, $password);

// From an upload or a secret store
A1PdfSign::newSignature()->certificateFromPem($bytes);
```

The encoding is decided by content, not by extension, since PEM ships as `.pem`, `.crt`, `.cer`, `.key` and `.txt`.
Pass an empty password when the private key is unencrypted: **PEM permits that and PKCS#12 does not**, and an
unprotected key on disk is readable by anything that can read the file.

### One call, when there is nothing to configure

The builder exists for the cases that need it. When none of them apply, there is a one-shot form for each source:

```php
A1PdfSign::signFromFile($pfxPath, $password, $pdfPath);
A1PdfSign::signFromPem($pemPath, $password, $pdfPath, $keyPath);
A1PdfSign::signFromUpload($request->file('certificate'), $password, $pdfPath);
```

### Storing a certificate

A certificate and its password can be encrypted for storage and read back later, so an application that signs on a
schedule does not keep either in plaintext:

```php
$stored = A1PdfSign::encryptCertificate($uploadedOrPath, $password);

$stored->hash;         // the key both values were encrypted with. Required to read them back
$stored->certificate;
$stored->password;

$certificate = A1PdfSign::decryptCertificate($stored->hash, $stored->certificate, $stored->password);
```

**The hash is the key**, so keep it somewhere other than the ciphertext it opens. Without it the pair cannot be read
back, by you or by anyone else.

[Working with certificates →](https://laravel-a1-pdf-sign.netlify.app/docs/2.x/working-with-certificate)

## PAdES profiles

| Profile | Adds |
|---|---|
| `legacy` | ISO 32000-1 detached CMS. Widest reader support |
| `pades-b-b` | CAdES signed attributes, with ESS `signing-certificate-v2`. **Default** |
| `pades-b-t` | plus an RFC 3161 timestamp, so the signing time is attested by a third party |
| `pades-b-lt` | plus a Document Security Store, so it still verifies after the certificate expires |
| `pades-b-lta` | plus an archive timestamp over the whole file |

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($path)
    ->profile('pades-b-lt')   // needs A1_TSA_URL configured
    ->sign();
```

An archive is a chain rather than a state, so it can be extended before the algorithms behind it weaken. **No
certificate is involved**: a DocTimeStamp is signed by the authority, not by the signer, so a scheduled job can do this
with no key material anywhere near it.

```php
A1PdfSign::extendArchive($path);
```

[Signature profiles →](https://laravel-a1-pdf-sign.netlify.app/docs/2.x/signature-profiles)

## Signing into a template's own fields

A contract laid out by someone else arrives with its signature fields already placed. `intoField()` fills the one you
name instead of appending another beside it:

```php
foreach (A1PdfSign::signatureFields($template) as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
}

A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($template)
    ->intoField('SignatureManager')
    ->seal()             // drawn into the field's own rectangle
    ->sign();
```

A field that is missing or already signed raises rather than falling back to appending. That fallback is the failure
this prevents: a signature that is valid and in the wrong place, with the template's field still empty.

## Certification and locks

```php
A1PdfSign::newSignature()->certificate($pfx, $password)->pdf($path)
    ->certify('form-filling')                  // no-changes | form-filling | annotations
    ->lock(FieldLock::only(['Amount']))        // ->lock() for every field
    ->sign();
```

A certification governs the whole document; a lock governs the fields you name. **The half that matters is the
reading**: a later signature into a field an existing lock covers is refused, rather than producing a document whose
earlier signature silently stopped verifying.

## Encrypted documents

A password-protected document is signed and re-encrypted under its own key, so the file stays consistent:

```php
A1PdfSign::newSignature()
    ->certificate($pfxPath, $certificatePassword)
    ->pdf($path, 'the document password')
    ->sign();
```

The document's password and the certificate's are different things and are passed separately: one opens the file, the
other unlocks the key that signs it. AES-128 and AES-256 are supported. **RC4 is refused**, because signing it would
mean writing RC4 back into a document in order to sign it.

## Validation

```php
$report = A1PdfSign::validate($pdfPath);

$report->isValid();     // every signature verifies against the bytes it covers
$report->count();       // how many signatures the document carries
$report->signers();     // structured signer identity
$report->isCertified(); // whether the author certified the document
```

`isValid()` means **the CMS actually verifies**. Each signature also reports what the document can prove about it:

```php
$signature = $report->latest();

$signature?->attestedAt();       // the timestamp authority's time, or null. Never the signer's own clock
$signature?->profile;            // the level it actually satisfies, not the one it claims
$signature?->isRevoked();        // what the document's own OCSP responses and CRLs say
$signature?->coversWholeDocument;
```

Revocation is evaluated from the material the document carries, and the material is verified against the issuer before
it is believed. **Nothing is fetched**: validation makes no network request and cannot be made to.

Whether to accept the signer is a separate question, answered against roots you name:

```php
$store = TrustStore::fromFile(storage_path('icp-brasil.pem'));

$report = A1PdfSign::validate($pdfPath, $store);
$report->isTrusted();   // ?bool. null when no store was given: nobody was asked
```

> [!NOTE]
> **The package ships no trust store and will not.** A bundled one goes stale between releases, and shipping it would
> make this package's release cadence the thing that decides whose signatures you accept. For ICP-Brasil, fetch the
> current chain from the ITI and keep it with your configuration. OpenSSL does the path validation, so intermediate
> validity, `basicConstraints`, key usage and name constraints are all checked rather than approximated.
>
> An untrusted signature is not an invalid one: the two questions are independent.

[Validating a signature →](https://laravel-a1-pdf-sign.netlify.app/docs/2.x/validating-signature)

## ICP-Brasil

A Brazilian certificate carries the holder's identity in `subjectAlternativeName`, not in the subject, and PHP renders
every one of those fields as `othername:<unsupported>`. This package reads them:

```php
$signer = A1PdfSign::validate($path)->signers()[0];

$signer->icpBrasil?->cpf;                 // '11144477735'
$signer->icpBrasil?->cnpj;                // the company, for an e-CNPJ
$signer->icpBrasil?->formattedRegistry(); // '11.222.333/0001-81'
$signer->name();                          // the name, without the number glued to it
```

A certificate can also be checked against the rules its own specification states, before anything is signed:

```php
$report = A1PdfSign::icpBrasil($pfxPath, $password);

$report->conforms();   // required fields, widths, alphabet, check digits, the CPF in two places agreeing
$report->messages();   // one line per finding, naming the field
```

> [!WARNING]
> **`conforms()` is not `isTrusted()`.** Every rule it checks is decidable from the certificate alone, so a self-signed
> certificate built to satisfy them will conform. Whether the chain reaches an ICP-Brasil root is `TrustStore`'s
> question, and it is a different one.

## Command line

```bash
php artisan pdf:sign contract.pdf certificate.pfx "password" signed.pdf
php artisan pdf:sign contract.pdf certificate.pem "" signed.pdf --key=private.key
php artisan pdf:validate-signature signed.pdf
```

[Commands →](https://laravel-a1-pdf-sign.netlify.app/docs/2.x/commands)

## Compatibility

| Package | Laravel | PHP | Documentation |
|---|---|---|---|
| **^2** | ^13 | 8.4 – 8.5 | [2.x](https://laravel-a1-pdf-sign.netlify.app/docs/2.x/home) |
| ^1 | ^9 – ^12 | 8.1 – 8.4 | [1.x](https://laravel-a1-pdf-sign.netlify.app/docs/1.x/home) |
| ^0 | ^8 | ^7.4 | [0.x](https://laravel-a1-pdf-sign.netlify.app/docs/0.x/home) |

Laravel 12 is not supported by v2, despite reaching PHP 8.5: it requires `symfony/process ^7.2` while the test
toolchain requires `^8.1`, so the two cannot be installed together.

Coming from 1.x? The v1 surface is **gone, not deprecated**, and [UPGRADE.md](UPGRADE.md) maps every removed API to its
replacement.

## Verified, not asserted

Signed output is checked against tools that were not written here, because a validator sharing its assumptions with the
signer proves very little:

| | |
|---|---|
| **poppler** `pdfsig` | reads the samples independently, and has caught defects the suite passed straight through |
| **veraPDF** | decides PDF/A and PDF/UA conformance, in CI and in the development image |
| **pyHanko** | enforces `/DocMDP`, so a certification broken by a later revision is caught by something that is not us |
| **qpdf** | checks structure, and reads back documents this package encrypted |

[`samples/`](samples/README.md) holds one signed document per profile plus a six-signature document. Open them in any
reader to see what the package produces.

## Contributing

Patches are expected to come with tests. `composer check` runs everything CI runs: Pint, PHPStan at level max with no
baseline, a dependency report and the suite.

```bash
docker compose -f .docker/compose.yaml run --rm php composer check
```

See [CONTRIBUTING.md](CONTRIBUTING.md), and [ARCHITECTURE.md](ARCHITECTURE.md) for how the package is put together and
why. The rules that break the product when violated are in [docs/spec/invariants.md](docs/spec/invariants.md), and the
reasoning behind the design is one numbered file per decision in [docs/decisions/](docs/decisions/README.md).

## Security

Found a vulnerability? Please follow [SECURITY.md](SECURITY.md) rather than opening a public issue.

## License

MIT. See [LICENSE.md](LICENSE.md).
