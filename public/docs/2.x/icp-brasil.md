#### 1 - Who signed, in the number Brazil knows them by. <small>(since 2.5)</small>

An ICP-Brasil certificate carries its holder's identity in `subjectAlternativeName`, not in the subject. The common name is the person's name with their CPF glued to the end after a colon, and everything structured lives in `otherName` entries under the 2.16.76.1.3 arc.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signer = A1PdfSign::validate('path/to/signed.pdf')->signers()[0];

$signer->icpBrasil?->cpf;                 // '11144477735'
$signer->icpBrasil?->cnpj;                // the company, for an e-CNPJ
$signer->icpBrasil?->formattedRegistry(); // '11.222.333/0001-81'
$signer->icpBrasil?->registry();          // the CNPJ when there is one, the CPF otherwise

$signer->name();                          // 'JOAO DA SILVA', without the number
$signer->commonName;                      // 'JOAO DA SILVA:11144477735', as written
```

**Before 2.5 the only way to get a CPF out of a certificate was `explode(':', $commonName)`.** That breaks on a name containing a colon, and it is simply wrong for an e-CNPJ: its common name carries the company, while the CPF in the extension belongs to whoever answers for it.

<hr>

#### 2 - Everything the certificate carries.

```PHP
$identity = $signer->icpBrasil;

$identity?->type;               // IcpBrasilCertificateType: Individual, LegalEntity or None
$identity?->birthDate;          // '11/08/1985'
$identity?->socialIdentity;     // NIS: PIS, PASEP or CI
$identity?->nationalId;         // RG, without the zeros it is padded with
$identity?->nationalIdIssuer;   // 'SSPSP'
$identity?->socialSecurity;     // CEI
$identity?->responsibleName;    // for an e-CNPJ, who answers for the company
$identity?->voterRegistration;
$identity?->raw;                // every otherName found, by OID, as written
```

A field the certificate fills with zeros, which the specification requires when a number is unavailable, comes back as `null`. "Absent" and "eleven zeros" are the same fact, and only one of them is worth handing to a caller.

`type` answers `None` for a certificate that is not ICP-Brasil at all, which is not the same as a malformed one: it never claimed to be.

<hr>

#### 3 - Checking a certificate before you sign with it.

```PHP
$report = A1PdfSign::icpBrasil('path/to/certificate.pfx', 'password');

$report->conforms();   // bool
$report->messages();   // list<string>, one line per finding, naming the field
$report->identity;     // the same IcpBrasilIdentity, parsed
```

What it checks, all of it stated by the specification about the certificate's own bytes:

| | |
|---|---|
| Required fields | three `otherName` entries for an e-CPF, four for an e-CNPJ |
| Widths | the layout's, with the last field of each allowed to run short |
| Alphabet | only A to Z and 0 to 9 |
| Check digits | modulus eleven, for both CPF and CNPJ |
| Birth date | a real date in `ddmmyyyy` |
| RG | an issuing authority named for a number that is absent, which the specification calls out |
| The CPF twice | the common name and the extension carry it separately, and nothing makes them agree |

The value is upstream of a rejection rather than after one: a certificate that fails here will be read wrong by everything downstream, and finding that out from the file beats finding it out from a filing that came back.

> **`conforms()` is not `isTrusted()`.** Every rule above is decidable from the certificate alone, so a self-signed certificate built to satisfy them all will conform. Whether the chain reaches an ICP-Brasil root is a different question, answered by [`TrustStore`](/docs/2.x/validating-signature), and neither answer implies the other.

<hr>

#### 4 - What this does not do.

**It never asks the Receita Federal anything.** A CPF that satisfies its check digits is a well-formed CPF, not a CPF that exists, and whether one was ever issued is a question only the Receita Federal answers. Asking would mean a network request during validation, which nothing in this package makes.

**It does not read the certificate policy.** The OID that says whether a certificate is A1, A3 or A4 is not checked, because that says which *kind* of certificate it is rather than whether it is well formed. It was left out rather than half done.
