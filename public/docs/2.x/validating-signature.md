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

> **`isValid()` means the CMS actually verifies.** In 1.x, "validated" meant the parsed subject contained a `CN` or `OU` field, which a document tampered with after signing still has. That check could not fail for any real certificate.

<hr>

#### 2 - Reading each signature.

```PHP
<?php

$report = A1PdfSign::validate('path/to/signed.pdf');

foreach ($report->signatures as $signature) {
    $signature->verified;             // bool, the CMS verifies against the covered bytes
    $signature->coverageEnd;          // int, byte offset this signature covers up to
    $signature->coversWholeDocument;  // bool
    $signature->isTimestamp;          // bool, a DocTimeStamp, not a signature by a signer
    $signature->error;                // ?string, why it failed, when it did

    // since 2.2
    $signature->signedAt;                    // ?CarbonInterface, the time the signer claimed
    $signature->signerWasValidWhenSigned();  // ?bool, null when the time is unknown
    $signature->chain;                       // list<Signer>, leaf first
    $signature->chainReachesRoot;            // bool, does it end at a self-signed root

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

In a document with several signatures, **only the last one covers the whole file**. Each earlier signature covers the document as it stood when that signature was made, and that is exactly what keeps them valid, and it is how a reader knows what each signer actually saw.

```PHP
$report->signatures[0]->coversWholeDocument; // false, signed before the others existed
$report->signatures[1]->coversWholeDocument; // false
$report->signatures[2]->coversWholeDocument; // true,  the most recent
```

A `false` here is not a defect. What would be a defect is an earlier signature that stopped verifying.

<hr>

#### 4 - Timestamps are not signatures.

A B-LTA document ends with a DocTimeStamp: a timestamp over the whole file, not a signature by a signer. It is reported separately and excluded from `isValid()`, because counting it as a signature would attribute the document to the timestamp authority.

```PHP
$report->timestamps(); // list<SignatureDetails> where isTimestamp is true
$report->signers();    // signers only, the authority is not one of them
```

<hr>

#### 5 - Signing time, and whether the certificate was valid then. <small>(since 2.2)</small>

A signature carries the time the signer claimed, in the signature dictionary's `/M`. It is worth being precise about what that is:

```PHP
$signature = $report->latest();

$signature?->signedAt;                    // ?CarbonInterface
$signature?->signerWasValidWhenSigned();  // ?bool
```

**`signedAt` is the signer's claim, not proof.** Anyone who controls the signing machine controls its clock. An RFC 3161 timestamp is the attested version of the same fact, which is what the `pades-b-t` profile and above add.

`signerWasValidWhenSigned()` returns **`null` rather than `false`** when the signing time is absent. A certificate whose validity window cannot be checked is unknown, not invalid, and collapsing the two would report a fact the document does not carry.

<hr>

#### 6 - Long-term validation material. <small>(since 2.2)</small>

A `pades-b-lt` document carries a Document Security Store: the certificate chain, OCSP responses and CRLs as they stood when the signature was made, so the signature is still checkable once the responders are gone and the certificate has expired.

```PHP
$report->hasLongTermMaterial();      // does every signature have material covering it?

$store = $report->securityStore;
$store?->certificates;               // int
$store?->ocspResponses;              // int
$store?->crls;                       // int
$store?->covers($signature);         // is there material for this signature specifically?
```

This reports **what is there, not that it is good**. Counting an OCSP response is not evaluating it: revocation is not checked at validation time, and a store with certificates but no entry for a given signature is carrying material for a different one.

<hr>

#### 7 - Certification. <small>(since 2.2)</small>

Whether the document's author certified it, and what that permits:

```PHP
$report->isCertified();               // bool
$report->certification;               // ?CertificationLevel
$report->acceptsFurtherSignatures();  // false only at no-changes
```

`acceptsFurtherSignatures()` is worth checking before signing a document you did not produce. At `no-changes` the package will refuse, because a further signature would invalidate the certification rather than add to it.

Half a certification is not reported as one: a `/Perms` entry naming a signature that carries no `/DocMDP` transform, or a `/FieldMDP` transform (which locks named fields and carries the same parameters), both report `null`.

<hr>

#### 8 - What validation does not do.

`isValid()` answers whether each signature matches the document. **It does not check the issuer against a trust store**: whether you trust the certificate authority is a policy decision, and it stays with your application:

```PHP
$signer = $report->latest()?->signer();

if ($signer?->issuerName() !== 'AC Certisign RFB G5') {
    // your rule, your call
}
```

<hr>

#### 9 - Verifying independently.

Our validator shares its assumptions with the code that produced the signature, so it is worth checking against something that does not. Poppler's `pdfsig` has caught bugs in this package that the whole test suite passed straight through:

```Shell
pdfsig path/to/signed.pdf
```
