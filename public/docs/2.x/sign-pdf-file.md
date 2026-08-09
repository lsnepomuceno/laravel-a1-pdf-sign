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
$signed->contents();               // string, the signed bytes
$signed->size();                   // int
$signed->save('path/to/out.pdf');  // string, the path written
$signed->download('contract.pdf'); // BinaryFileResponse, forces a download
$signed->toResponse();             // Response, renders inline in the browser
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

        // PDF already in memory: from another package, a stream, storage
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

Everything after the certificate is identical: profiles, seals, timestamps and multiple signatures all behave the same, because the two encodings converge on one pipeline as soon as the certificate is parsed.

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

Omitting `seal()` produces an invisible signature, which is still a valid one: the seal is an appearance, not part of the cryptography.

<hr>

#### 6 - Each signature carries its own seal.

A seal belongs to one signature, not to the document. Sign twice and each signature gets its own image, its own position and its own page, with nothing shared between them.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

// First signer: a seal rendered from their certificate
$first = A1PdfSign::newSignature()
    ->certificate('path/to/first.pfx', 'password')
    ->pdf('path/to/contract.pdf')
    ->info(name: 'First signer')
    ->seal(placement: new SealPlacement(x: 150, y: 240, width: 50))
    ->sign();

$path = $first->save(storage_path('contract-signed.pdf'));

// Second signer: their own image, somewhere else on the page
$second = A1PdfSign::newSignature()
    ->certificate('path/to/second.pfx', 'password')
    ->pdf($path)
    ->info(name: 'Second signer')
    ->sealFrom('path/to/handwritten.png', new SealPlacement(x: 30, y: 60, width: 60))
    ->sign();
```

Both seals are visible in the finished document, in different places. One signature can be visible while another is invisible: simply omit `seal()` on the one that should not show, and it stays cryptographically valid all the same.

This works because each signature is an appended revision carrying its own widget annotation, with its own image and form objects. Nothing is reused between them, so changing the second seal cannot disturb the first.

[`samples/two-seals.pdf`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/samples/two-seals.pdf) is exactly this case, signed and ready to open in a reader.

<hr>

#### 7 - Signing into a field the document already carries. <small>(since 2.2)</small>

A contract laid out by someone else arrives with its signature fields already placed. `intoField()` fills the one you name, instead of appending another beside it.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

// What does this template carry?
foreach (A1PdfSign::signatureFields('path/to/contract-template.pdf') as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->pageNumber;  // 3
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
    $field->isVisible(); // true, the field has an area to draw into
}

$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/contract-template.pdf')
    ->intoField('SignatureManager')
    ->seal()
    ->sign();
```

**The field's own rectangle decides where the seal goes**, because the template already drew the box. For that reason `intoField()` cannot be combined with a `SealPlacement`, and a field with a zero rectangle keeps the signature invisible even when `seal()` was called: the template's geometry is the template's decision.

Three things raise `SignatureFieldException` rather than falling back to appending a field:

| Refused | Why |
|---|---|
| A field that does not exist | the message names the fields that do, since a misspelling is the usual cause |
| A field already signed | filling it again would replace that signature rather than add one |
| A placement passed as well | resolving by precedence would silently move the seal off the box the template drew |

> **Falling back would be worse than failing.** Before 2.2 the package appended a new field beside the empty one, so the document ended up with a signature that was valid and in the wrong place, plus an unfilled field that was the point of the template.

[`samples/signed-into-fields.pdf`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/samples/signed-into-fields.pdf) is a template with two fields, both filled by name.

<hr>

#### 8 - Certifying a document. <small>(since 2.2)</small>

Every signature above is an **approval** signature: it asserts what the bytes were. A **certification** is a different claim, the author's statement about what may happen to the document from here on (ISO 32000-1 §12.8.2.2).

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$certified = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/contract.pdf')
    ->certify(CertificationLevel::FormFilling)   // or the string 'form-filling'
    ->info(name: 'Document author')
    ->seal()
    ->sign();
```

| Level | Permits |
|---|---|
| `no-changes` | nothing. The document **cannot be signed again** |
| `form-filling` | filling form fields and signing. **The default** |
| `annotations` | form filling, signing and annotations |

`certify()` defaults to `form-filling` because a document that still has to be signed is the common case, and defaulting to the level that refuses the next signer would fail closed in the wrong direction.

Three rules are enforced, not merely documented, each raising `CertificationException`:

- **A certification has to be the first signature.** It states what may happen from here on, and an approval signature already applied is a thing that happened;
- **There can be only one** per document;
- **`no-changes` refuses every later signature**, approval or otherwise.

> **That last rule can reach code that never calls `certify()`.** Signing a document someone else certified at `no-changes` raises rather than succeeding. This is the certification working: a further signature is a further revision, and without the refusal it would silently invalidate the signature already there. If the document still has to be signed, certify at `form-filling` instead.

[`samples/certified.pdf`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/samples/certified.pdf) is certified at `form-filling` and then signed by a second party.

<hr>

#### 9 - Documents produced by Word, Chrome and LaTeX. <small>(since 2.2)</small>

No API change, and nothing to call. It is worth knowing about because 2.1 refused these documents outright.

PDF 1.5 replaced the cross-reference table with a **cross-reference stream**, and that is the form Word, "print to PDF" in Chrome, LaTeX with compression and most modern generators emit. 2.2 reads it and appends the new revision in whichever form the document already uses.

The two cannot be mixed: appending a classic table to a document whose latest section is a stream produces a file that readers do not see as signed at all. If you are signing documents from a source that previously failed, this is why.
