# Signature profiles

A profile decides what evidence travels inside the signature. Each level adds to the one before it, and the default is **PAdES B-B**.

| Profile | `/SubFilter` | Adds |
| --- | --- | --- |
| `legacy` | `adbe.pkcs7.detached` | ISO 32000-1 detached CMS. Widest reader support |
| `pades-b-b` | `ETSI.CAdES.detached` | CAdES signed attributes, with ESS `signing-certificate-v2`. **Default** |
| `pades-b-t` | `ETSI.CAdES.detached` | plus an RFC 3161 timestamp |
| `pades-b-lt` | `ETSI.CAdES.detached` | plus a Document Security Store — the chain, OCSP responses and CRLs |
| `pades-b-lta` | `ETSI.CAdES.detached` | plus an archive timestamp over the whole file |

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

// By enum
A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->profile(SignatureProfile::PadesBLT)
    ->sign();

// Or by its string value, which is what the config file holds
->profile('pades-b-lt')

// Shorthand for pades-b-t
->timestamp()
```

Leaving `profile()` out uses `a1-pdf-sign.signature.profile`.

<hr>

## Which one to pick

**`pades-b-b` if you have no particular requirement.** It is the baseline every modern reader understands, and it carries the ESS `signing-certificate-v2` attribute that binds the signature to a specific certificate. This attribute is why the package builds the CMS with `tc-lib-pdf-sign` instead of `openssl_pkcs7_sign()`, which cannot emit it.

**`pades-b-t` when the signing time has to be provable.** A signature carries whatever clock the signer's machine reported; a timestamp from an RFC 3161 authority is evidence a third party attested. It needs an authority configured:

```PHP
// config/a1-pdf-sign.php
'timestamp' => [
    'url'     => env('A1_TSA_URL', 'https://freetsa.org/tsr'),
    'timeout' => 20,
],
```

Without a configured URL, requesting a timestamped profile throws rather than silently producing a weaker signature.

**`pades-b-lt` when the document must still verify years from now.** A signature stops verifying once its certificate expires or is revoked, unless the document itself carries the revocation evidence gathered while the certificate was still good. That evidence is the Document Security Store, appended as a further revision after the signature it vouches for.

**`pades-b-lta` for long-term archival.** An archive timestamp over the whole file attests the validation material along with the signature, so the chain of evidence can be extended before the algorithms behind it weaken.

<hr>

## What happens when the material is unavailable

A self-signed certificate has neither an OCSP responder nor a CRL distribution point, and a responder can simply be unreachable. In both cases the document stays at the level below rather than failing the signature — signing must not depend on a third party being up.

<hr>

## A note on network access

Timestamp, OCSP and CRL requests go through an injected HTTP transport. **The host application owns that SSRF surface**: the URLs come from your configuration, and the package never derives an endpoint from the document being signed.
