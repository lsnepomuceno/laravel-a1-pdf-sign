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

use LSNepomuceno\LaravelA1PdfSign\Data\SealLayout;
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

// The same seal on every page
$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->seal(placement: new SealPlacement(x: 155, y: 250, width: 50, onEveryPage: true))
    ->sign();

// An image you already have, skipping the renderer
$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->sealFrom('path/to/seal.png')
    ->sign();

// What the seal says and where, overriding the certificate-derived lines
// (since 2.4)
$signed = A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/document.pdf')
    ->seal(layout: SealLayout::saying(['Approved', 'Protocol 4471']))
    ->sign();
```

> **Fixed in 2.4.** `sealFrom()` wrote the image path into the placement and nothing read it back, so the artwork was silently replaced by a render of the certificate. If you called `sealFrom()` before 2.4, your documents were not carrying your image.

**A seal with an alpha channel stays transparent since 2.4**, where before it was flattened onto white. It costs more bytes, because PDF has no PNG filter and the alpha travels as a separate `/SMask` image, and **it makes PDF/A-1 impossible**: §6.4 forbids `/SMask` outright. `'transparent' => false` in the config is the lever, and that is the whole reason the setting exists.

**The page is counted from one, in the order the page tree declares.** `SealPlacement::LAST_PAGE` is the default, so a placement that names no page puts the seal on the last one. A page the document does not have raises `SealPlacementException` rather than being clamped to the nearest one: a seal asked for on page 7 of a three-page contract is a mistake, and putting it on page 3 would look deliberate.

> **Fixed in 2.3.1.** Before that release `page` and `onEveryPage` were both ignored and every seal landed on the first page. If you signed multi-page documents with 2.3.0 or earlier and want the seal to stay on page 1, pass `page: 1` explicitly.

`onEveryPage` still produces **one** signature. A signature is one form field with one widget, so the widget goes on the first page and every further page gets a stamp annotation drawing the same appearance. The image is embedded once whatever the page count, and every stamp is written inside the signature's own revision, so the signature covers them.

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

PDF 1.5 introduced **two** compression structures, and a document from those producers normally uses both:

| | |
|---|---|
| Cross-reference stream (§7.5.8) | indexes the objects. Read and written since 2.2 |
| Object stream (§7.5.7) | packs the objects themselves. Read since **2.3** |

The two cross-reference forms cannot be mixed: appending a classic table to a document whose latest section is a stream produces a file that readers do not see as signed at all, so the revision follows whichever form is already there.

Object streams took a further release because reading the index is not enough. The catalog is a dictionary, and a dictionary is exactly what gets packed; signing rewrites the catalog to register the field, so a catalog it cannot read is a document it cannot sign. **2.2 read the index and still refused most of these documents.** Nothing is unpacked in place: the revision writes the changed objects back at the top level, and the newer cross-reference entry supersedes the packed one.

If you are signing documents from a source that previously failed, this is why.

<hr>

#### 10 - Locking the fields a signature covers. <small>(since 2.4)</small>

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Data\FieldLock;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

A1PdfSign::newSignature()
    ->certificate('path/to/certificate.pfx', 'password')
    ->pdf('path/to/contract.pdf')
    ->lock()                                    // every field
    ->sign();

->lock(FieldLock::only(['Amount', 'DueDate'])) // those two
->lock(FieldLock::except(['Countersign']))     // everything but that one
```

A lock is **a narrower claim than certifying**. A certification governs the whole document; a lock governs the fields you name. One signature can make both, and they are written as two transforms in one `/Reference` array (ISO 32000-1 §12.7.4.5).

**The half that matters is the reading, not the writing.** A later `sign()` into a field an existing lock covers raises `FieldLockException` instead of producing a document whose earlier signature silently stopped verifying. Locks other producers wrote are honoured the same way as the ones this package writes.

<hr>

#### 11 - Extending an archive. <small>(since 2.4)</small>

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$extended = A1PdfSign::extendArchive('path/to/archived.pdf');
```

**No certificate, no key, no password.** A DocTimeStamp is signed by the timestamp authority rather than by the signer, so extending a `pades-b-lta` archive is something a scheduled job can do with no key material anywhere near it.

An archive timestamp is a chain, not a state. Each new one covers the whole file including the previous timestamp, so the evidence can be renewed before the algorithms behind the older links weaken. It needs the same authority configured as `pades-b-t` and above.
