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

    // since 2.4
    $signature->hasTimestamp();       // bool, is there an RFC 3161 token at all
    $signature->timestampVerified;    // ?bool, does it verify and really stamp this signature
    $signature->attestedAt();         // ?int, the authority's time, never the signer's
    $signature->profile;              // ?SignatureProfile, the level it actually satisfies
    $signature->subFilter;            // ?string, the /SubFilter as written
    $signature->revocation;           // RevocationStatus
    $signature->isRevoked();          // bool

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

Up to 2.3 this reported **what was there, not that it was good**: counting an OCSP response is not evaluating one. Since 2.4 the material is evaluated, and section 8 is that.

A store with certificates but no entry for a given signature is still carrying material for a different one, which is what `covers()` answers.

<hr>

#### 7 - The timestamp, verified. <small>(since 2.4)</small>

A `pades-b-t` document carries an RFC 3161 token. Up to 2.3 the report could say the signature verified and nothing about the token on it.

```PHP
$signature = $report->latest();

$signature?->hasTimestamp();      // is there a token at all
$signature?->timestampVerified;   // ?bool
$signature?->attestedAt();        // ?int, the genTime a verified token asserts
$signature?->profile;             // ?SignatureProfile
$signature?->subFilter;           // ?string
```

**`attestedAt()` returns `null` rather than falling back to `signedAt`.** They answer different questions: one is a third party's clock, the other is the signer's own, and a caller reading an unattested time as an attested one is the mistake the method exists to prevent.

`timestampVerified` is `null` when there is no token, which is the ordinary case at `pades-b-b`. **An absence is not a failure**, and reporting it as `false` would say the token was checked and rejected.

`profile` reports the level the signature **actually satisfies**, read from what the document carries. `subFilter` is the value as written, for a caller who would rather read it themselves:

```PHP
$signature?->subFilter;   // 'ETSI.CAdES.detached', what it claims
$signature?->profile;     // SignatureProfile::PadesBB, what it can prove
```

A document can claim a level it does not reach. A `/SubFilter` of `ETSI.CAdES.detached` says nothing about whether a timestamp is present, so the two are reported separately rather than one being derived from the other.

<hr>

#### 8 - Revocation, evaluated. <small>(since 2.4)</small>

```PHP
$signature = $report->latest();

$signature?->revocation;   // RevocationStatus: Good, Revoked or Unknown
$signature?->isRevoked();  // bool
```

The OCSP responses and CRLs the document carries are parsed, **verified against the issuer**, and then read. All three steps matter: an OCSP response signed by a delegated responder is only believed once that responder is shown to have been issued by a certificate in the chain (RFC 6960 §4.2.2.2). Without that check a response could vouch for itself.

`Unknown` is a real answer, and it covers three different situations: the document carries no material, it carries some but none mentioning this certificate, or what it carries does not verify against the issuer. None of those is evidence of revocation, and reporting them as `Good` would be inventing evidence.

> **`isRevoked()` is separate from `verified`, and it has to be.** A revoked certificate still produces a signature that matches the bytes perfectly. What it stops being is one anyone should accept.

This reads only what the document already carries. **Nothing is fetched at validation time**, so validation makes no network request and cannot be made to.

<hr>

#### 9 - Certification. <small>(since 2.2)</small>

Whether the document's author certified it, and what that permits:

```PHP
$report->isCertified();               // bool
$report->certification;               // ?CertificationLevel
$report->acceptsFurtherSignatures();  // false only at no-changes
```

`acceptsFurtherSignatures()` is worth checking before signing a document you did not produce. At `no-changes` the package will refuse, because a further signature would invalidate the certification rather than add to it.

Half a certification is not reported as one: a `/Perms` entry naming a signature that carries no `/DocMDP` transform, or a `/FieldMDP` transform (which locks named fields and carries the same parameters), both report `null`.

<hr>

#### 10 - Trust. <small>(since 2.3)</small>

`isValid()` answers whether each signature matches the document. Whether to accept the signer is a separate question, and it is answered against roots you name:

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Validation\TrustStore;

$store = TrustStore::fromFile(storage_path('icp-brasil.pem'));
// or ::fromPem($bundle), ::fromDirectory($path), ::empty()

$report = A1PdfSign::validate($pdfPath, $store);

$report->isTrusted();           // ?bool, across every signature
$report->latest()?->isTrusted;  // ?bool, per signature
```

**The package ships no trust store and will not.** A bundled one goes stale between releases, and shipping it would make this package's release cadence the thing that decides whose signatures you accept. For ICP-Brasil, fetch the current chain from the ITI and keep it where your application keeps its configuration.

There are three answers, not two:

| | |
|---|---|
| `null` | no store was given. Nobody was asked, so there is nothing to report |
| `false` | a store was given and the chain does not reach it |
| `true` | the chain validates against it |

> **An untrusted signature is not an invalid one.** The two questions are independent, and a document can be one without the other. Reading `null` as "untrusted" would conclude something the run never established, which is why the two are kept apart.

OpenSSL does the path validation, so each intermediate's validity window, `basicConstraints`, key usage, name constraints and path length are all checked rather than approximated. One consequence: a self-signed certificate carrying `basicConstraints CA:FALSE` is **not** accepted as its own trust anchor even when handed in as the root. That is correct, and stricter than a naive check.

<hr>

#### 11 - Who signed, under ICP-Brasil. <small>(since 2.5)</small>

```PHP
$signer = $report->signers()[0];

$signer->icpBrasil?->cpf;   // '11144477735'
$signer->name();            // the name, without the number glued to it
```

A Brazilian certificate carries its holder's identity in `subjectAlternativeName` rather than in the subject, and it has its own page: [ICP-Brasil](/docs/2.x/icp-brasil).

<hr>

#### 12 - What validation still does not do.

**It never goes to the network.** Revocation is evaluated since 2.4, but only from the material the document already carries. A signature whose certificate was revoked *after* signing, with no OCSP response in the file saying so, reports `Unknown`: the answer is somewhere on the internet, and fetching it is the host application's decision rather than the validator's.

That is the same rule as everywhere else here. Signing reaches an authority because it has to; validation reads bytes.

<hr>

#### 13 - Verifying independently.

Our validator shares its assumptions with the code that produced the signature, so it is worth checking against something that does not. Poppler's `pdfsig` has caught bugs in this package that the whole test suite passed straight through:

```Shell
pdfsig path/to/signed.pdf
```

<hr />

#### 14 - When validation cannot answer at all. <small>(since 2.6)</small>

Validation shells out to `openssl`, and until 2.6 an environment that could not run it reported **every signature as invalid**. Not an error: a verdict, which a caller could not tell from a tampered document.

```PHP
use LSNepomuceno\LaravelA1PdfSign\Exceptions\{MissingBinaryException, ProcessUnavailableException};

try {
    $report = A1PdfSign::validate($path);
} catch (MissingBinaryException $e) {
    // the openssl binary is not on the PATH
} catch (ProcessUnavailableException $e) {
    // proc_open is disabled, as on much shared hosting
}
```

**`ext-openssl` being loaded is a different thing from the binary being installed.** A signature that genuinely does not verify still returns a report with `verified` false, unchanged.

`php artisan a1-pdf-sign:check` answers this before you sign anything.

<hr />

#### 15 - Catching this package's failures as a group. <small>(since 2.6)</small>

Every exception implements `Exceptions\A1PdfSignException`, so an application no longer has to name sixteen classes or catch `\Exception` and swallow everything the framework throws with them:

```PHP
use LSNepomuceno\LaravelA1PdfSign\Exceptions\A1PdfSignException;

// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (A1PdfSignException $e) {
        // anything this package considers its own failure
    });
})
```

The classes stay granular beneath it. **`InvalidCertificatePasswordException`** is the one worth catching on its own, since a wrong password is the failure a production application meets most, and it extends `InvalidCertificateContentException`, the class it used to arrive as, so an existing catch still matches.
