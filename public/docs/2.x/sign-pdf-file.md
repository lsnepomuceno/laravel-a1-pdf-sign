#### 1 - Signing a document.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

class ExampleController
{
    public function dummyFunction()
    {
        $signed = A1PdfSign::newSignature()
            ->certificate('path/to/certificate.pfx', 'password')
            ->pdf('path/to/document.pdf')
            ->info(name: 'Lucas', reason: 'Contract', location: 'Brazil')
            ->sign();
    }
}
```

`sign()` returns a `SignedPdf`, and **it does not decide how the result is delivered**. The `MODE_RESOURCE` / `MODE_DOWNLOAD` choice from 1.x is gone; the same result can be consumed in several ways:

```PHP
$signed->contents();               // string — the signed bytes
$signed->size();                   // int
$signed->save('path/to/out.pdf');  // string — the path written
$signed->download('contract.pdf'); // BinaryFileResponse — forces a download
$signed->toResponse();             // Response — renders inline in the browser
(string) $signed;                  // same as contents()
```

<hr>

#### 2 - Signing from an upload, or from bytes you already hold.

```PHP
<?php

use Illuminate\Http\Request;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

class ExampleController
{
    public function dummyFunction(Request $request)
    {
        // Certificate from an upload
        $signed = A1PdfSign::newSignature()
            ->certificateFromUpload($request->file('certificate'), $request->input('password'))
            ->pdf('path/to/document.pdf')
            ->sign();

        // PDF already in memory — from another package, a stream, storage
        $signed = A1PdfSign::newSignature()
            ->certificate('path/to/certificate.pfx', 'password')
            ->pdfContents($pdfBytes, 'contract.pdf')
            ->sign();
    }
}
```

<hr>

#### 3 - Signing with a PEM certificate. <small>(since 2.1)</small>

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

// One-shot, certificate and key in the same file
$signed = A1PdfSign::signFromPem('path/to/certificate.pem', 'password', 'path/to/document.pdf');

// One-shot, key in a file of its own
$signed = A1PdfSign::signFromPem('path/to/certificate.crt', 'password', 'path/to/document.pdf', 'path/to/private.key');

// Or through the builder, where the rest of the fluent API is available
$signed = A1PdfSign::newSignature()
    ->certificatePem('path/to/certificate.pem', password: 'password')
    ->pdf('path/to/document.pdf')
    ->info(name: 'Lucas', reason: 'Contract')
    ->seal()
    ->sign();
```

The encoding is read from the file's content rather than its extension, and the password may be empty when the private key is unencrypted. [Working with certificates](/docs/2.x/working-with-certificate) covers both in detail.

Everything after the certificate is identical — profiles, seals, timestamps and multiple signatures all behave the same, because the two encodings converge on one pipeline as soon as the certificate is parsed.

<hr>

#### 4 - Signing the same document more than once.

Each signature is appended as a new revision, so the ones before it stay valid.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$first = A1PdfSign::newSignature()
    ->certificate('path/to/first.pfx', 'password')
    ->pdf('path/to/contract.pdf')
    ->info(name: 'First signer')
    ->sign();

$path = $first->save(storage_path('contract-signed.pdf'));

$second = A1PdfSign::newSignature()
    ->certificate('path/to/second.pfx', 'password')
    ->pdf($path)
    ->info(name: 'Second signer')
    ->sign();
```

Signature fields must not collide, so each revision gets its own name automatically (`Signature1`, `Signature2`, …). Override it when you need a specific field name:

```PHP
->fieldName('ContractSignature')
```

**This is what changed in 2.0.** In 1.x the second call rebuilt the document and the first signature was lost.

<hr>

#### 5 - Visible signatures.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

// A seal rendered from the certificate, using the configured defaults
$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->seal()
    ->sign();

// Position, size and page of your choosing
$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->seal(
        placement: new SealPlacement(x: 155, y: 250, width: 50, page: SealPlacement::LAST_PAGE),
        fontSize: FontSize::Large,
        showExpiry: true,
    )
    ->sign();

// An image you already have, skipping the renderer
$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->sealFrom('path/to/seal.png')
    ->sign();
```

Omitting `seal()` produces an invisible signature, which is still a valid one — the seal is an appearance, not part of the cryptography.
