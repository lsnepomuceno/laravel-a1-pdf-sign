#### 1 - Validating a signed document.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

class ExampleController
{
    public function dummyFunction()
    {
        $report = A1PdfSign::validate('path/to/signed.pdf');

        $report->isSigned();  // does the document carry a signature at all?
        $report->isValid();   // does every signature verify against the bytes it covers?
        $report->count();     // how many signatures
        $report->signers();   // list<Signer>
        $report->latest();    // the last signature, or null
    }
}
```

> **`isValid()` means the CMS actually verifies.** In 1.x, "validated" meant the parsed subject contained a `CN` or `OU` field — which a document tampered with after signing still has. That check could not fail for any real certificate.

<hr>

#### 2 - Reading each signature.

```PHP
<?php

$report = A1PdfSign::validate('path/to/signed.pdf');

foreach ($report->signatures as $signature) {
    $signature->verified;             // bool — the CMS verifies against the covered bytes
    $signature->coverageEnd;          // int — byte offset this signature covers up to
    $signature->coversWholeDocument;  // bool
    $signature->isTimestamp;          // bool — a DocTimeStamp, not a signature by a signer
    $signature->error;                // ?string — why it failed, when it did

    $signer = $signature->signer();
    $signer?->commonName;
    $signer?->organization;
    $signer?->organizationalUnit;
    $signer?->email;
    $signer?->serialNumber;
    $signer?->issuerName();
    $signer?->isExpired();
}
```

<hr>

#### 3 - What `coversWholeDocument` tells you.

In a document with several signatures, **only the last one covers the whole file**. Each earlier signature covers the document as it stood when that signature was made — that is exactly what keeps them valid, and it is how a reader knows what each signer actually saw.

```PHP
$report->signatures[0]->coversWholeDocument; // false — signed before the others existed
$report->signatures[1]->coversWholeDocument; // false
$report->signatures[2]->coversWholeDocument; // true  — the most recent
```

A `false` here is not a defect. What would be a defect is an earlier signature that stopped verifying.

<hr>

#### 4 - Timestamps are not signatures.

A B-LTA document ends with a DocTimeStamp: a timestamp over the whole file, not a signature by a signer. It is reported separately and excluded from `isValid()`, because counting it as a signature would attribute the document to the timestamp authority.

```PHP
$report->timestamps(); // list<SignatureDetails> where isTimestamp is true
$report->signers();    // signers only — the authority is not one of them
```

<hr>

#### 5 - What validation does not do.

`isValid()` answers whether each signature matches the document. **It does not check the issuer against a trust store** — whether you trust the certificate authority is a policy decision, and it stays with your application:

```PHP
$signer = $report->latest()?->signer();

if ($signer?->issuerName() !== 'AC Certisign RFB G5') {
    // your rule, your call
}
```

<hr>

#### 6 - Verifying independently.

Our validator shares its assumptions with the code that produced the signature, so it is worth checking against something that does not. Poppler's `pdfsig` has caught bugs in this package that the whole test suite passed straight through:

```Shell
pdfsig path/to/signed.pdf
```
