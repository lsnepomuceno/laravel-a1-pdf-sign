# Testing your application

An application that signs PDFs has to test the code path that signs them. Doing that for real means a PKCS#12 bundle in your repository, a real PDF, and a full CMS built for every test that merely passes through the flow.

#### 1 - Sign nothing, and assert what would have been signed. <small>(since 2.6)</small>

```PHP
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

it('signs the contract when the deal is accepted', function () {
    $signing = A1PdfSign::fake();

    $this->post('/deals/42/accept')->assertOk();

    $signing->assertSigned();
});
```

`fake()` returns the recorder. It replaces `Contracts\PdfSigner` and `Contracts\CertificateReader` in the container, so `certificate()` accepts any path, nothing is parsed, no key is generated and no document is written.

> It replaces those two rather than the facade itself, deliberately. The builder is the documented way in and depends on both, so faking only the facade would leave `newSignature()->…->sign()` reaching the real signer.

#### 2 - The assertions.

```PHP
$signing->assertSigned();                     // something was signed
$signing->assertSigned('CONTRACT-42');        // a document containing this
$signing->assertSignedTimes(2);               // how many
$signing->assertNothingSigned();              // the negative
$signing->assertSignedWithProfile(SignatureProfile::PadesBLT);
$signing->assertCertified();                  // any certification
$signing->assertCertified(CertificationLevel::NoChanges);
$signing->assertSealed();                     // a visible seal was asked for
```

`assertSigned()` takes a **fragment of the document** rather than a path, because the signer is handed bytes and never learns where they came from. In practice you assert on something the document says.

**`assertNothingSigned()` is usually the one that catches a bug**: a flow that signs when it should not is harder to notice than one that does not sign at all.

#### 3 - The result is usable.

```PHP
$signed = A1PdfSign::newSignature()
    ->usingCertificate(A1PdfSignFake::certificate())
    ->pdfContents($bytes)
    ->sign();

$signed->contents;   // a small valid PDF
$signed->size();     // an integer
$signed->save($path);
```

Application code calls those, so the fake hands back a real `SignedPdf` rather than a null that would fail somewhere unhelpful.

#### 4 - What it does not fake.

**Validation.** `A1PdfSign::validate()` still reads the document you give it. Dictating a `SignatureReport` is a different feature from recording what was signed, and it is not here yet.

If your application only needs a document that validates, sign one for real in a fixture and read it back: that path is fast, since it builds no timestamp and reaches no network.

#### 5 - Checking the environment instead. <small>(since 2.6)</small>

A test suite proves your code is right. It says nothing about whether the machine can sign at all, and the failure there used to be silent:

```Shell
php artisan a1-pdf-sign:check
```

See [Commands](/docs/2.x/commands).
