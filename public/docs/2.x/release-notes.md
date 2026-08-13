# 2.6.0

## Three answers that were wrong, and silent about it

Each of these produced a result rather than an error, which is the worst shape a defect can take in a package whose job is to answer a question about a document.

### A missing `openssl` binary made every signature report as invalid

Validation shells out. `Validation\SignatureVerifier` caught every throwable and returned `false`, on reasoning that was correct for the case it named: a non-zero exit from `openssl smime -verify` means the signature does not verify. The catch was wider than the reasoning.

Measured on `samples/pades-b-b.pdf`, changing nothing but the environment:

| Environment | Before | Now |
|---|---|---|
| `openssl` on `PATH` | valid | valid |
| binary removed | **invalid** | `MissingBinaryException` |
| `proc_open` disabled | **invalid** | `ProcessUnavailableException` |

A caller could not tell that apart from a tampered document, and the natural response to "invalid" is to reject something legitimate.

**`ext-openssl` being loaded says nothing about the command-line tool being installed.** A minimal container commonly has the first without the second, and that distinction is what costs people an afternoon.

```Shell
php artisan a1-pdf-sign:check
```

answers the same question before anything is signed.

### Signature metadata was written as raw UTF-8

`/Name`, `/Reason`, `/Location` and `/ContactInfo` are text strings, and ISO 32000-1 §7.9.2.2 allows two forms: PDFDocEncoding, or UTF-16BE with a byte order mark. Raw UTF-8 is neither.

A conforming reader found no mark, decoded as PDFDocEncoding, and showed the two bytes of `ã` as two characters. **`João` displayed as `JoÃ£o`**, in a document that verified perfectly, which is why nothing caught it: every assertion was about the signature verifying, and it did.

ASCII output is byte-identical to 2.5. Anything else is now a hex string with the mark.

### The seal ignored page rotation

`/Rotate` turns a page clockwise for display and leaves its coordinate system alone. `grep -rn "Rotate" src/` returned nothing: the key was read nowhere, so on a page carrying `/Rotate 90`, which is how most scanners express landscape, the seal landed elsewhere and read sideways.

The rectangle is mapped into user space and the appearance carries a matrix turning it back. Confirmed by rendering with poppler rather than by arithmetic: asked for at 7-21% across and 23-33% down, rendered at 7-21% and 24-33%.

### Validation could not read a signature this package did not write

The `/ByteRange` pattern required the exact bytes this package emits. A document from another producer, which writes `/ByteRange [0 9875 15069 565]` with a space, found no signatures and raised as unsigned.

Every validation test signed with this package first, so the defect was structurally invisible.

<hr>

## Signing a 200 MB document

Peak memory was roughly 20 MB plus **four times** the document. It is now 20 MB plus **two**.

| Document | Before | Now |
|---|---|---|
| 25 MB | 95 MB | 70 MB |
| 100 MB | 320 MB | 220 MB |
| 200 MB | 620 MB | **420 MB** |

**One breaking change comes with it.** `Contracts\PdfSigner::sign()` takes the document by reference, so it can release it once the revision exists. PHP cannot pass an expression by reference:

```PHP
$signer->sign(Files::read($path), $certificate, $info);   // fatal
$contents = Files::read($path);
$signer->sign($contents, $certificate, $info);            // fine
```

`A1PdfSign::newSignature()` and the one-shot helpers pass a property and are unaffected, so this reaches only an application calling the contract directly.

<hr>

## Testing an application that signs

```PHP
$signing = A1PdfSign::fake();

// … your application runs …

$signing->assertSigned();
$signing->assertSignedWithProfile(SignatureProfile::PadesBLT);
$signing->assertCertified(CertificationLevel::NoChanges);
$signing->assertSealed();
```

**No PKCS#12 bundle in your repository, and no CMS built** for a test that merely passes through the signing call. It replaces the signer and the certificate reader in the container, so `certificate()` accepts any path and nothing is parsed, rendered or signed.

[Testing your application →](/docs/2.x/testing)

<hr>

## Catching failures as a group

Every exception now implements `Exceptions\A1PdfSignException`:

```PHP
$exceptions->report(function (A1PdfSignException $e) { … });
```

The classes stay granular beneath it. **`InvalidCertificatePasswordException` is new**, for the failure a production application meets most, and it extends the class a wrong password used to arrive as, so existing catches still match.

The distinction is evidence rather than a guess: OpenSSL answers a wrong password with a MAC verify failure and a broken file with an ASN.1 error, and the MAC is computed with a key derived from the password.

<hr>

## Auditing what was signed

Off by default, because a package that logs unasked fills somebody's disk.

```PHP
$this->app->bind(SigningLog::class, fn () => new SigningLog(Log::channel('audit')));
```

**No password, key, document or file path can appear in a line**, whatever is passed: the context is filtered against the keys that may appear rather than the keys that may not. A denylist is how the next property added to a data object leaks. A path is excluded too, since it is enough to find the bundle it names.

[Auditing →](/docs/2.x/auditing)

<hr>

## Smaller, and worth knowing

**A document does not have to be a local file.**

```PHP
->pdfFromDisk('s3', 'contracts/deal.pdf')
```

**The seal renderer is swappable**, and always was. `Contracts\SealRenderer` is bound in the container, so one line in your own service provider replaces it with a QR code, a corporate logo or any layout of your own. `fromImage()` is the route for artwork produced elsewhere, Blade included.

**Timestamp and revocation requests go through the framework's HTTP client**, so `Http::fake()` intercepts them, your proxy and middleware apply, and a transient failure retries instead of failing the signature. A network failure raises `SignatureTransportException` rather than an exception named after processes.

**`psr/log` is a new runtime dependency.**

**Signed documents declare the ETSI_PAdES extension** their sub-filter needs below PDF 2.0, so the bytes of every signed document change.

<hr>

## Measured rather than claimed

**PDF/UA.** An invisible signature keeps an accessible document conformant; a visible seal costs it two clauses, ISO 14289-1 7.18.1 and 7.18.4. Measured with veraPDF, and the failures are asserted clause by clause so an improvement breaks the test rather than passing quietly.

**Certification is enforced, not merely written.** pyHanko compares the appended revisions against the `/DocMDP` policy and reaches a verdict, which closes a caveat 0012 carried for two releases: a document certified at no-changes and then modified is now reported as violating its policy on every run.

**What this package writes is checked against the specification's own grammar.** The Arlington PDF Model is the PDF Association's machine-readable ISO 32000, and it found a real disagreement in its first five minutes, which is what earned it a place.

<hr>

# 2.5.0

## A visible seal no longer costs PDF/A conformance

The seal was embedded as `/DeviceRGB`, which PDF/A allows only where the file declares an OutputIntent, so a conformant document came back non-conformant. It now carries its own `/ICCBased` profile and asks the document for nothing.

| | 2.4 | 2.5 |
|---|---|---|
| PDF/A-1b, opaque seal | FAIL | **PASS** |
| PDF/A-1b, transparent seal | FAIL | FAIL, and always will: §6.4 forbids `/SMask` |
| PDF/A-2b, opaque seal | FAIL | **PASS** |
| PDF/A-2b, transparent seal | FAIL | **PASS** |

The profile is **built rather than vendored**, from the numbers IEC 61966-2-1 publishes, so no third party's binary and no third party's licence enters an MIT package. Its computed colorants match the published sRGB profile to four decimal places, which is asserted rather than assumed: veraPDF validates the container, not the colours, so a structurally valid profile with the wrong primaries would pass every conformance check and render the seal in the wrong colour.

**A sealed document grows by about 2.4 KB.** An invisible signature embeds nothing and is unchanged.

<hr>

## Who signed, in the number Brazil knows them by

```PHP
$signer = A1PdfSign::validate($path)->signers()[0];

$signer->icpBrasil?->cpf;                 // '11144477735'
$signer->icpBrasil?->cnpj;                // the company, for an e-CNPJ
$signer->icpBrasil?->formattedRegistry(); // '11.222.333/0001-81'
$signer->name();                          // 'JOAO DA SILVA', without the number
```

The identity lives in `subjectAlternativeName`, and PHP renders every one of those fields as `othername:<unsupported>`, so until now the only way to get a CPF was `explode(':', $commonName)`. That breaks on a name containing a colon and is wrong for an e-CNPJ, whose common name carries the company while the CPF belongs to whoever answers for it.

`A1PdfSign::icpBrasil()` also checks a certificate against the rules its own specification states, and says which field is wrong before anything is signed. **`conforms()` is not `isTrusted()`**: every rule is decidable from the certificate alone, so a self-signed certificate built to satisfy them will conform.

[ICP-Brasil →](/docs/2.x/icp-brasil)

<hr>

## A document protected by a password can be signed

```PHP
A1PdfSign::newSignature()
    ->certificate($pfxPath, $certificatePassword)
    ->pdf($path, 'the document password')
    ->sign();
```

Encrypted documents used to be refused outright, which was correct rather than good: the cross-reference table is not encrypted, so reading gets far enough to look successful while everything around it is unreadable, and a plaintext revision beside it produces a file whose new objects no reader can decrypt.

The standard security handler is implemented for **AES-128 and AES-256**. The document's password and the certificate's are different things and are passed separately: one opens the file, the other unlocks the key that signs it.

**RC4 is refused**, because signing an RC4 document means writing RC4 back into it, and this package will not weaken a file in order to sign it. Also refused, each with a message naming why: a non-standard security handler, an encrypted document packed into object streams, and `pades-b-lt` and above while encrypted.

Verified against qpdf in both directions: the fixtures are qpdf's output, and qpdf reads the signed result back.

<hr>

## Extending an archive refreshes the evidence it archives

`extendArchive()` used to append the timestamp and nothing else, so a document could gain a fifth archive timestamp over revocation material gathered on the day it was signed. That is the one thing long-term validation exists to prevent.

It now gathers fresh material for every chain the document carries and writes the store **before** the timestamp, which is the order ETSI EN 319 142-1 fixes: the evidence goes inside the file while it is still verifiable, and the timestamp then covers it. The timestamp authorities' own certificates are included, because they are what the next archive timestamp has to be able to check.

<hr>

## Also

- **`SECURITY.md`**, saying what counts as a vulnerability and, more usefully, what looks like one and is a documented decision.
- The README was rewritten. It had never mentioned reading a CPF, signing an encrypted document, field locks or extending an archive.
- Two docblock rules are now executable, after four stacked docblocks turned up across the package, one of them describing `latest()` while sitting above `timestamps()`.
- `main` is built after a merge. A pull request is tested against its own branch, not against main with it merged, and two branches that are each green can produce a main that is not.

<hr>

## Upgrading

No public class was removed and no signature was narrowed. `Contracts\A1PdfSign` gained `icpBrasil()` and `Contracts\PdfSigner::sign()` gained a document password argument, both of which matter only to someone implementing those interfaces. `Data\Signer` gained `$icpBrasil` and `name()`, appended with defaults.

**The bytes of every sealed document change**, because the seal's colour space did.

# 2.4.0

## Validation reads what signing writes

2.3 could produce a PAdES B-LT document and then tell you almost nothing about one. The report said a signature verified; it could not say whether the timestamp on it was real, which level the signature actually reached, or what the document's own revocation material said about the signer.

```PHP
$report->signatures[0]->attestedAt();   // the authority's time, or null
$report->signatures[0]->profile;        // the level it satisfies, not the one it claims
$report->signatures[0]->isRevoked();    // what the embedded OCSP and CRLs say
```

`attestedAt()` returns null rather than falling back to `signedAt`. The signer's own clock answers a different question, and a caller reading an unattested time as an attested one is the exact mistake the method exists to prevent.

`isRevoked()` is deliberately separate from `verified`. **A revoked certificate still produces a signature that matches the bytes perfectly.** What it stops being is one anyone should accept.

<hr>

## ⚠️ Two things change what you already get

### The seal keeps its alpha channel

`a1-pdf-sign.seal.transparent` defaults to `true`.

| | Before | Now |
|---|---|---|
| A PNG with an alpha channel | flattened onto white | drawn transparent |
| Bytes added | JPEG | deflated samples plus an `/SMask`, larger |
| PDF/A-1 conformance | possible | **impossible**: §6.4 forbids `/SMask` |

Set `'transparent' => false` for the old opaque rectangle. That is the whole reason the setting exists rather than the behaviour being unconditional.

### `sealFrom()` uses the image you gave it

`SealPlacement::$imagePath` was written by `sealFrom()` and read by nothing at all, so the caller's artwork was silently replaced by a render of the certificate. **If you called `sealFrom()`, your documents were not carrying your image, and now they will.**

<hr>

## Locks, and honouring the ones already there

```PHP
->lock()                                   // every field
->lock(FieldLock::only(['Amount']))        // that one
->lock(FieldLock::except(['Countersign'])) // everything else
```

Writes `/Lock` and `/FieldMDP` as two transforms in one `/Reference` array (ISO 32000-1 §12.7.4.5). A narrower claim than certifying: a certification governs the document, a lock governs named fields.

**The other half is the half that matters.** A later `sign()` into a field an existing lock covers is now refused, instead of producing a document whose earlier signature silently broke.

<hr>

## The archive timestamp is a chain

```PHP
A1PdfSign::extendArchive($path);
```

No certificate, no key, no password. A DocTimeStamp is signed by the authority rather than by the signer, so refreshing a B-LTA archive is something a scheduled job can do with no key material anywhere near it.

<hr>

## Filters documents actually use

`Support\PdfFilters` decodes Flate, LZW with `/EarlyChange`, ASCII85, ASCIIHex and run-length, with the PNG and TIFF predictors. 2.3 read compressed objects only when they happened to be plain Flate, which is a guess that holds until it does not.

<hr>

## What this release is really about

Three of the five PAdES levels this package advertises could regress **without CI going red.** Everything above `pades-b-b` needs a timestamp authority, the tests for it were in the non-blocking `network` group, and that was demonstrably not theoretical: three of those tests were committed broken, went green, and were noticed only by reading a log.

`Contracts\SignatureTransport` is the fix. The HTTP transport was already injected and already the only thing in `src/` that opens a connection, but it was `final` and its three collaborators took the concrete class. **Injection you cannot substitute is not injection.**

`Testing\LocalTimestampAuthority` answers with real RFC 3161 tokens from `openssl ts -reply`, which needs no server and no network. They are signed, verifiable, and carry the imprint of the bytes they were handed, so the offline tests assert that the timestamp verified rather than asserting that something was embedded. A stub returning canned bytes would have proved nothing: the imprint has to match the signature value produced in that run.

Six behaviours moved from reported to gated, including PDF/A conformance at B-LTA. The live tests against a real authority stay, because a local authority cannot establish that the package interoperates with somebody else's.

<hr>

## PDF/A, measured rather than assumed

veraPDF now runs in the development image and in CI, and it blocks. The test command carries `--fail-on-skipped`, because every check has to run somewhere and a skip is how one quietly stops running.

- **An invisible signature keeps a PDF/A-1b and PDF/A-2b document conformant.** That is now a supported claim. It was not true before this release: a signature field with no appearance dictionary fails §6.9, and all six combinations measured failed until it was fixed.
- A visible seal costs conformance, and the reason is the colour space rather than the signature.

<hr>

## Also fixed

- The appended revision carries the trailer `/ID`. Without it, a reader may treat the revision as belonging to a different document.
- An OCSP response signed by a delegated responder is verified against the issuer before being read (RFC 6960 §4.2.2.2). It could previously vouch for itself.
- The archive timestamp's widget gets an appearance dictionary, the same §6.9 rule the invisible signature hit.

<hr>

## Upgrading

No public class was removed and no signature was narrowed, so an application that calls the facade upgrades without changes. Four contracts gained members, which matters only to someone implementing one, and the three collaborators of the HTTP transport now take `Contracts\SignatureTransport` rather than the concrete class.

# 2.3.1

## The seal goes where it was asked for

`SealPlacement` has carried `$page` and `$onEveryPage` since 2.0. **Nothing read either of them.**

Every seal went onto the first page, whatever was asked for, while the documentation showed this as a supported call:

```PHP
new SealPlacement(x: 155, y: 250, width: 50, page: SealPlacement::LAST_PAGE)
```

`appliesTo()` was written to answer exactly this question and had no caller anywhere in `src/`.

That is the worst class of defect this package can carry. Not a refusal, not a crash, not a feature a reader can see is missing: a documented parameter that silently produces a plausible wrong result, and the wrong result is a signed contract with the signature on the wrong page.

<hr>

## ⚠️ This moves an existing seal

| | Before | Now |
|---|---|---|
| `new SealPlacement(...)`, no page given | first page | **last page**, which `$page`'s default, `LAST_PAGE`, has always named |
| `page: 2` | first page | page 2 |
| `onEveryPage: true` | first page | every page |
| A page the document does not have | first page | `SealPlacementException` |

**Single-page documents are unaffected in every case.**

If your seals were landing on page 1 of a multi-page document and you want them to stay there, pass `page: 1` explicitly. The value was previously ignored, so no call site can be relying on it having meant something else.

<hr>

## Page order comes from the page tree

`DocumentReader::pages()` walks `/Pages` and `/Kids` from the catalog (ISO 32000-1 §7.7.3.2).

The scan it replaces read the cross-reference table in object-number order and took the first `/Type/Page`. **Object numbers carry no page order.** A producer may write the last page first, and any generator that rewrites a page gives it a fresh number at the end of the file, so that answer could only ever be right by accident. It survives as the fallback for a document whose tree cannot be walked.

The test fixture numbers its pages backwards on purpose: a fixture numbered in reading order cannot tell a tree walk apart from the scan it replaced.

<hr>

## `onEveryPage` is one signature, not one per page

A signature is one form field with one widget, so the seal cannot be a widget on every page: a widget that is not a form field is invalid, and a second signature field would be a second signature.

The widget goes on the first page the placement accepts, and every further page gets a `/Subtype/Stamp` annotation (§12.5.6.12) whose `/AP` points at **the same form XObject**. One image object serves the whole document, so a ten-page seal embeds the JPEG once rather than ten times.

Every stamp is written inside the signature's own revision, so its bytes fall within `/ByteRange` and the signature covers them like everything else it wrote.

<hr>

## Out of range raises, rather than clamping

`page: 7` on a three-page document throws `SealPlacementException`.

Clamping to the last page is the quiet answer and quiet is the whole defect. A caller who asks for page 7 of a three-page contract has made a mistake, and a signed document with the seal on page 3 looks deliberate.

<hr>

## Fixed

- **`TrustStore::fromDirectory()` was a fatal error on Alpine.** It globbed `"*.{pem,crt,cer}"` with `GLOB_BRACE`, a GNU extension PHP leaves undefined on musl, so on `php:8.4-alpine` the call raised `Undefined constant` before `glob()` was reached. It shipped in 2.3.0 and the suite stayed green for the whole release, because CI runs on Ubuntu where the constant exists.

  The behavioural test can only ever check the platform it happens to run on, so the guard is structural: `tests/ArchTest.php` now fails on any platform-optional constant appearing in `src/`.

## Added

- **`Exceptions\SealPlacementException`**, raised by `sign()` for a page the document does not have.

## Verified

Independently, with poppler: `pdfsig` reports every probe *Signature is Valid* and *Total document signed*, and `pdftoppm` renders the seal on page 2 alone, on page 3 alone, and on all three, as asked.

<hr>

**No API signature changed and the PHP and Laravel requirements do not move.** [`UPGRADE.md`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/UPGRADE.md) covers the seal move; [`0017`](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md) records why each part is shaped as it is.

<hr>

# 2.3.0

## Trust, and the documents 2.2 still refused

Two things, and the first is a correction.

**2.2 claimed to sign documents from Word and Chrome. It did not.** PDF 1.5 has two compression structures, not one: the cross-reference stream that indexes objects, which 2.2 read and wrote, and the **object stream** that packs them, which it could not read at all. The catalog is a dictionary, and a dictionary is exactly what gets packed. Signing rewrites the catalog to register the field, so most documents from those producers were still refused, with an accurate error rather than a corrupt file.

2.3 reads them. Nothing is unpacked in place: the revision writes the changed objects back at the top level, uncompressed, and the newer cross-reference entry supersedes the packed one. The original bytes survive.

**And `isValid()` finally has a companion.** It has always answered "does this signature match these bytes" and never "should I accept this signer". That split is right, but the package stopped one step too early: not even the mechanism was there.

```PHP
$store = TrustStore::fromFile(storage_path('icp-brasil.pem'));

$report = A1PdfSign::validate($path, $store);

$report->isTrusted();           // ?bool, across every signature
$report->latest()?->isTrusted;  // ?bool, per signature
```

<hr>

## The package ships no trust store, and will not

A bundled one goes stale between releases, and shipping it would make **this package's release cadence the thing that decides whose signatures you accept**. For ICP-Brasil, fetch the current chain from the ITI and keep it with your application's configuration.

Choosing whom to trust is policy and stays with you. Verifying a chain against the roots you named is mechanism, and that is what ships.

**Three answers, not two:**

| | |
|---|---|
| `null` | no store was given. Nobody was asked, so there is nothing to report |
| `false` | a store was given and the chain does not reach it |
| `true` | the chain validates against it |

An **untrusted** signature is not an **invalid** one. The two questions are independent, and a document can be one without the other.

<hr>

## OpenSSL does the path validation

`openssl_x509_checkpurpose()` builds and validates the path, so each intermediate's validity window, `basicConstraints`, key usage, name constraints and path length are all checked.

Walking the chain by hand would have verified only that each certificate was signed by the next, which `ChainBuilder` already did, and would have **accepted chains a reader rejects**. That is the worst direction for this particular answer to be wrong in.

One consequence worth knowing: a self-signed certificate carrying `basicConstraints CA:FALSE` is **not** accepted as its own trust anchor, even when handed in as the root. That is correct, and stricter than a naive check would be.

<hr>

## Added

- **`TrustStore`**, from a PEM bundle, a file, a directory or empty, and a trailing `?TrustStore` on `A1PdfSign::validate()`;
- **`SignatureReport::isTrusted()` and `SignatureDetails::$isTrusted`**, both tri-state;
- **Object stream support** (ISO 32000-1 §7.5.7), reading packed objects and writing them back uncompressed, with no API change;
- **`DebugCertificate::makeChain()`**, a root authority and a certificate it issued, for testing trust against the shape a real certificate has;
- **`samples/object-stream.pdf`**, two signatures on a document whose catalog is packed.

## Fixed

- **The end-of-line before `endstream` was being read as data.** It belongs to the syntax (§7.3.8.1), and keeping it corrupted an unfiltered stream payload by one byte. A compressed payload tolerates the extra byte, which is how it stayed hidden behind both callers.

## Internal

- **`Support\Pem`** replaces four copies of the same certificate-extraction pattern and a fifth that encoded DER back into armour;
- **`src/Support` joined the nightly mutation matrix.** Extracting shared helpers there had quietly taken them out of the gate they had been under, since the matrix names namespaces rather than following code;
- **The backward compatibility check now reports rather than blocks.** It fired correctly on this release's contract changes; a gate that fails on every release of that shape is one that gets switched off. What it finds goes into the job summary instead.

<hr>

## ⚠️ Breaking for implementers

Calling the contracts is unaffected: the new parameters are optional and trailing. **Implementing them is not.**

| | 2.2 | 2.3 |
| --- | --- | --- |
| `Contracts\A1PdfSign::validate()` | `$pdfPath` | gains `?TrustStore $trust = null` |
| `Contracts\SignatureValidator::validateFile()` | `$pdfPath` | gains `?TrustStore $trust = null` |
| `Contracts\SignatureValidator::validate()` | `$pdfContents, $label` | gains `?TrustStore $trust = null` |
| `Data\SignatureDetails::toArray()` | 10 keys | gains `isTrusted` |

The last one reaches anyone asserting on the whole array. The [upgrade guide](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/UPGRADE.md) maps each one.

<hr>

## Still not done, and named rather than implied

- **Revocation is not evaluated.** The Document Security Store's OCSP responses and CRLs are counted, not read. Long-term validation reports what material is present, not what it says;
- **Seals cannot be transparent.** They embed as JPEG, so a seal is always an opaque rectangle;
- **A3 certificates, tokens and HSMs** are out of scope. The key never leaves the device, which is a different architecture from the one this package has.

<hr>

# 2.2.1

## A break 2.2.0 shipped, and the gate that found it

2.2.0 raised `IncrementalSigner`'s constructor from six required arguments to eight. Resolving the signer from the container, which every documented path does, is unaffected. **Building it by hand is not**, and that is a break a patch release should undo rather than a note in an upgrade guide.

Both new parameters now default to an instance:

```PHP
new IncrementalSigner($reader, $writer, $byteRange, $cades, $dss, $archiveTimestamp);
```

Nothing else changed. There is no reason to upgrade from 2.2.0 unless you construct that class directly.

<hr>

## How it was found

The Roave backward compatibility check now runs on every pull request, comparing the last release against `HEAD`. It was deliberately held back from the 2.2 work so its first run could be read rather than merged blind, and it earned its place immediately: **nothing in the test suite could have caught this**, because the suite resolves everything from the container.

It also surfaced a second change 2.2.0 made and failed to document: `InvalidPdfFileException::__construct()` renamed its first parameter from `currentFile` to `message`. Behaviour is unchanged for a positional caller, since the argument is still a string that becomes the message. Only a named argument breaks:

```PHP
new InvalidPdfFileException(currentFile: $path);  // 2.1
new InvalidPdfFileException(message: $text);      // 2.2
```

The one case the old wording described kept it byte for byte, as `InvalidPdfFileException::extension($path)`.

<hr>

## Fixed

- **`Signing\IncrementalSigner::__construct()` accepts six arguments again.** `SignatureFieldReader` and `CertificationReader` default to an instance instead of being required.

## Added

- **A backward compatibility gate on every pull request**, comparing the last SemVer tag against `HEAD`. The baseline moves on its own as releases are cut, so there is no list to maintain.

<hr>

# 2.2.0

## Templates, certification, and the documents 2.1 refused

Three things a real workflow needs and 2.1 could not do: sign a PDF produced by Word or Chrome, fill the signature field a contract template already carries, and certify a document as its author.

```PHP
// The field the template already drew, filled by name
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($contract)
    ->intoField('SignatureManager')
    ->seal()
    ->sign();

// The author's statement about what may happen from here on
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($contract)
    ->certify('form-filling')
    ->sign();
```

Nothing was removed and the PHP and Laravel requirements do not move, so an application that signs and validates upgrades without editing anything.

<hr>

## Documents 2.1 could not sign at all

> **Corrected after release.** This section originally said 2.2 signs documents produced by Word and by "print to PDF" in Chrome. That was wider than what shipped. PDF 1.5 has **two** compression structures: the cross-reference stream, which 2.2 reads and writes, and the **object stream**, which packs the catalog and pages and which 2.2 could not read. Signing rewrites the catalog, so most documents from those producers were still refused, with an accurate error rather than a corrupt file. 2.3.0 closes it.

**PDF 1.5 replaced the cross-reference table with a stream**, and most modern generators emit that form. 2.1 refused every document using it, which bounded who could use the package, and the bound was not small.

2.2 reads that form and appends a revision in whichever form the document already uses. The mixture is not a matter of taste: appending a classic table to a document whose latest section is a stream produced a file poppler reported as carrying **no signatures at all**. Reading shipped one release before writing, with signing refusing in between, so the gap was a loud refusal rather than silent corruption.

<hr>

## Added

- **`intoField()`**, which fills a signature field the document already carries instead of appending one beside it. Until now the package left the template's own field empty while putting a valid signature somewhere else. The field's rectangle decides where the seal goes;
- **`A1PdfSign::signatureFields()`**, returning each field's name, rectangle, page and whether it is already signed;
- **`certify()`**, writing a `/DocMDP` certification at `no-changes`, `form-filling` or `annotations` (ISO 32000-1 §12.8.2.2), requested in [discussion #160](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/160);
- **Cross-reference stream support**, reading and writing, with no API change. Documents that also pack objects into object streams, which is most of them in practice, needed 2.3.0;
- **`signedAt` and `signerWasValidWhenSigned()`** on each signature. The second returns `null` rather than `false` when the signing time is absent, because a validity window that cannot be checked is unknown, not invalid;
- **`chain` and `chainReachesRoot`**, the embedded certificates ordered leaf first, with each link confirmed by the issuer's public key rather than by matching names;
- **`securityStore` and `hasLongTermMaterial()`**, so a `pades-b-lt` document can be asked whether its validation material actually covers every signature in it;
- **`isCertified()`, `certification` and `acceptsFurtherSignatures()`** on the report;
- **Archive timestamps are verified** rather than assumed, against the imprint they actually stamp;
- **`samples/certified.pdf`, `samples/signed-into-fields.pdf` and `samples/xref-stream.pdf`**, one per new capability.

<hr>

## Refusals, which are the feature

Each of these raises rather than falling back, because every fallback here produces a file that looks right and is not.

| Refused | Why |
|---|---|
| `intoField()` naming a field that does not exist | appending one beside it is exactly the failure the feature prevents. The message names the fields that do exist |
| `intoField()` naming a field already signed | filling it again would replace that signature rather than add one |
| A seal placement passed with `intoField()` | the field has its own rectangle; resolving by precedence would silently move the seal off the box the template drew |
| A second certification, or one after an approval signature | a certification states what may happen from here on, and a signature already applied is a thing that happened |
| **Any signature on a document certified at `no-changes`** | a further signature is a further revision, which is exactly what that level forbids |
| An encrypted document | the cross-reference table is not encrypted, so reading gets far enough to look successful while everything around it is unreadable |

The `no-changes` one can reach code that uses no new feature at all, if it signs a document someone else certified. That is the certification working: without the refusal, the second signature would silently invalidate the first.

<hr>

## Fixed

- **The first page of a compact document was misidentified as the catalog.** The page search scanned a fixed 400-byte window from each object's offset, which in a document whose objects sit close together reaches the objects that follow. The revision then wrote the form entry and the annotation onto the same object, the second silently dropping the first, producing a document with a signature dictionary and no form to reach it from. It was latent in any such document, and only a 434-byte test fixture was small enough to expose it;
- **Exceptions name the fault that actually occurred.** Fifteen of sixteen call sites reported structural faults as "Invalid file extension".

<hr>

## Breaking for implementers

Calling or injecting the contracts is unaffected: the new parameters are optional and trailing. **Implementing them is not.**

| | 2.1 | 2.2 |
| --- | --- | --- |
| `Contracts\A1PdfSign` | n/a | gains `signatureFields()` |
| `Contracts\PdfSigner::sign()` | 7 parameters | gains `?string $intoField` and `?CertificationLevel $certification` |
| `Data\SignatureReport::toArray()` | 2 keys | gains `certification` |

The last one reaches anyone asserting on the whole array, a snapshot test or a strict equality check. Reading properties and calling methods is unaffected. The [upgrade guide](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/UPGRADE.md) maps each one.

<hr>

## One verification this release does not claim

**Whether a reader enforces a certification is untested.** `pdfsig` does not surface `/DocMDP` at all, so poppler confirms only that the file is well formed and both signatures verify. Enforcement needs Adobe Reader or ITI Validar, which this project cannot run in CI.

The bytes are right and were checked by hand: `/Perms` names the signature that carries the transform, and the transform carries the permission for the level asked for. `samples/certified.pdf` exists so you can check the rest yourself. [Decision 0012](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/docs/decisions/0012-certification-signatures.md) records the gap rather than rounding it up.

<hr>

# 2.1.0

## PEM certificates

**PKCS#12 is no longer the only encoding the package reads.** A PEM certificate can be handed to the signer directly, with no `openssl pkcs12 -export` step first, which was the only answer this package had for two years ([discussion #147](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/discussions/147)).

```PHP
A1PdfSign::signFromPem('path/to/certificate.pem', 'password', 'path/to/document.pdf');

A1PdfSign::newSignature()
    ->certificatePem('path/to/certificate.crt', 'path/to/private.key', 'password')
    ->pdf('path/to/document.pdf')
    ->sign();
```

PKCS#12 is not a peer of PEM but a container that is converted into it, so the two share one pipeline: the encoding is decided at the entry point and everything after it (profiles, seals, timestamps, multiple signatures, validation) is the same code. `PemCertificateReader` implements the existing `CertificateReader` contract as the degenerate case, the reader whose conversion step is empty.

<hr>

## Added

- **`certificatePem()` and `certificateFromPem()`** on the builder, and **`signFromPem()`** on the facade and the `A1PdfSign` contract. The private key may sit in the same file as the certificate or in one of its own;
- **The encoding is read from the content, never the extension.** PEM ships as `.pem`, `.crt`, `.cer`, `.key` and `.txt`, so gating on the suffix would reject valid files;
- **An empty password is accepted**, because a PEM private key is frequently unencrypted, legal for PEM and impossible for PKCS#12. OpenSSL ignores a passphrase given for a key that does not need one;
- **`pdf:sign` takes `--key`** for the two-file form. Passing it with a PKCS#12 bundle is rejected rather than ignored: the bundle already carries its key, so the combination means the caller is mistaken about what they hold;
- **`encryptCertificate()` accepts PEM**, detecting the encoding instead of gaining a sibling: it takes "a certificate" generically, where signing keeps explicit entry points;
- **`InvalidPemContentException`**, which names the offending half: binary DER or PKCS#12 bytes handed to the PEM entry point are reported as misrouted, not as a generic parse failure;
- **`samples/certificate.pem`**, the identity `samples/certificate.pfx` already carried, in the second encoding.

<hr>

## Fixed

- **A passphrase-protected private key could not be checked against its certificate.** `CertificateParser` passed the bundle to `openssl_x509_check_private_key()` as a string, which cannot decrypt it. PKCS#12 never reached that path, since `openssl_pkcs12_read()` returns an already-decrypted key, so the defect only surfaced once PEM arrived. The array form is correct for encrypted and unencrypted keys alike, so nothing branches on it.

<hr>

## ⚠️ Breaking for implementers

Calling or injecting the contracts is unaffected. **Implementing them is not.**

| | 2.0 | 2.1 |
| --- | --- | --- |
| `Contracts\A1PdfSign` | n/a | gains `signFromPem()` |
| `Contracts\CertificateReader::read()` | `$pfxContents` | `$contents` |

The parameter was named after PKCS#12 when that was the only encoding a reader could ingest. Every call site in the package is positional, so the rename reaches you only through a named argument. The `pdf:sign` argument `pfxPath` became `certificatePath` for the same reason: positional on the command line, so only `Artisan::call()` with named keys is affected.

The [upgrade guide](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/UPGRADE.md) maps each one.

<hr>
<hr>

# 2.0.0

## 💥 Breaking Changes

### Version 2 is a clean break. The 1.x surface was removed, not deprecated.

There is no compatibility layer. Every 1.x entry point is gone, and the [Upgrade guide](/docs/2.x/upgrade-from-1x) maps each one to its replacement.

- The **global helper functions** (`signPdfFromFile()`, `signPdfFromUpload()`, `encryptCertData()`, `decryptCertData()`, `validatePdfSignature()`, `a1TempDir()`) no longer exist. Everything goes through the `A1PdfSign` facade;
- `LSNepomuceno\LaravelA1PdfSign\Sign\*` (`ManageCert`, `SignaturePdf`, `ValidatePdfSignature`, `SealImage`) was removed;
- `LSNepomuceno\LaravelA1PdfSign\Entities\*` was replaced by `Data\*`;
- String constants (`MODE_RESOURCE`, `FONT_SIZE_LARGE`, `IMAGE_DRIVER_GD`) became enums;
- Minimum requirements are now **Laravel 13** and **PHP 8.4**.

<hr>

## The reason for the rewrite

**A second signature used to destroy the first.** Signing re-imported every page through FPDI and rebuilt the document, which discarded annotations, form fields and any signature already present, the behaviour reported in [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430), open since 2021.

Version 2 signs by **appending a revision** (ISO 32000-1 §7.5.6). The original bytes survive byte for byte, so every earlier signature stays valid and each one covers the document as it stood when that signature was made.

Verified with poppler's `pdfsig` on a document carrying six signatures: all six report *Signature is Valid*.

<hr>

## Added

- **PAdES profiles**: B-B, B-T, B-LT and B-LTA, alongside the legacy ISO 32000-1 profile. See [Signature profiles](/docs/2.x/signature-profiles);
- **Real cryptographic validation.** 1.x reported a document as "validated" when the parsed subject contained a `CN` or `OU` field, which a tampered document still has. Validation now verifies the CMS against the bytes each signature covers;
- **Multiple signatures** on one document, each preserving the ones before it;
- **RFC 3161 timestamps**, embedded OCSP responses and CRLs (Document Security Store), and archive timestamps;
- **A fluent builder** as the primary API, plus contracts bound in the container so every part is swappable;
- **Publishable configuration**: temporary path, signature profile, timestamp authority, seal driver, font and background;
- `#[\SensitiveParameter]` on every password argument, so certificate passwords stop appearing in stack traces.

<hr>

## Removed

- **TCPDF and FPDI.** TCPDF 6 was discontinued by its author on 2026-05-30; the engine is now `tecnickcom/tc-lib-pdf-sign`, its official successor;
- The private key **written in plain text** to `src/Temp/` inside the consuming application's `vendor/`;
- The certificate password travelling on the command line, where `ps` exposed it to any user on the machine;
- The `openssl` binary as a hard requirement. Certificates are read through `ext-openssl`; the CLI remains only as the fallback for legacy PFX files under OpenSSL 3.x.
