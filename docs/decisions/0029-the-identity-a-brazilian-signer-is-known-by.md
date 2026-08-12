# 0029: The identity a Brazilian signer is known by

**Status:** implemented.

## Context

This package exists for Brazilian signing, and could not answer the first
question anyone asks of a signed document: **who signed it.**

In Brazil that answer is a CPF or a CNPJ, and the package handed back this:

```php
$signer->commonName;   // "JOAO DA SILVA:11144477735"
```

The number is glued onto the name with a colon, and the structured data, birth
date, NIS, RG, voter registration, the company and whoever answers for it, lives
somewhere the package never looked: the `subjectAlternativeName`, as `otherName`
entries under the 2.16.76.1.3 arc.

**Every consumer was therefore writing `explode(':', $commonName)`**, which
breaks on the first name containing a colon and gives the wrong answer entirely
for an e-CNPJ, whose common name carries the company's CNPJ while the CPF in the
extension belongs to a different person.

### Why it was not read before

`openssl_x509_parse()` cannot do it. It renders the extension as text, and an
`otherName` under an OID it does not know comes back as
`othername:<unsupported>`, which is every ICP-Brasil field. There was no reader
that could go further until [0019](0019-validation-reads-what-it-writes.md) built
`Validation\Asn1Reader` for the CMS.

## Decision

Read the fields from the DER, model them, and check them.

### Reading

`Certificates\SubjectAlternativeNameReader` walks the certificate to the
extension (RFC 5280 §4.2.1.6) and returns the `otherName` entries by OID.
`Certificates\IcpBrasilReader` slices each one by the widths the Receita
Federal's specification fixes, §2.2.5 for e-CPF and §3.2.5 for e-CNPJ, and
returns a `Data\IcpBrasilIdentity`.

Three details in that layout are worth stating, because each one is a way to
read a field wrong:

- **There are no separators.** A field read one character short reads the next
  one wrong rather than failing, so the widths are the whole grammar.
- **One field is allowed to be short**, and only the last of its layout: the
  six positions for the RG's issuing authority "refer to the maximum size, and
  only the positions needed are used". It is therefore read as the remainder.
- **"Unavailable" is written as zeros**, which the specification requires. A
  caller gets null, because eleven zeros and an absent field are the same fact
  and only one of them is worth handing over.

`Data\Signer` gained `$icpBrasil`, so validating a document answers the question
directly, and `name()`, which returns the common name without the number stuck
to it.

### Checking, and the word "structural"

`Validation\IcpBrasilValidator` returns a `Data\IcpBrasilReport`. Every rule it
applies is one the specification states about the bytes:

| | |
|---|---|
| Required fields | three `otherName` entries for an e-CPF, four for an e-CNPJ |
| Widths | the layout's, with the last field allowed to run short |
| Alphabet | "only the characters A to Z and 0 to 9 may be used" |
| Check digits | modulus eleven, for both CPF and CNPJ |
| Birth date | a real date in `ddmmyyyy` |
| RG | an issuing authority named for a number that is absent is a fault the specification names |
| The CPF twice | the common name and the extension both carry it, and nothing in the format makes them agree |

**`conforms()` is not `isTrusted()`, and keeping those apart is the whole risk
this feature carries.** A self-signed certificate can be built to satisfy every
rule above, and `Testing\DebugCertificate::icpBrasil()` builds exactly that, for
the tests. What it cannot do is chain to an ICP-Brasil root, which is the
question [0016](0016-trust-is-the-applications-policy.md) answers and this one
does not touch.

The value is upstream of trust rather than instead of it: a certificate that
fails here will be read wrong by everything downstream, and finding that out
from the bytes beats finding it out from a rejected filing.

## Consequences

- **`Data\Signer` gained two members**, `$icpBrasil` and `name()`. Appended with
  a default, so existing construction is unaffected.
- **`Contracts\A1PdfSign` gained `icpBrasil()`**, a break for anyone
  implementing the interface, which the Roave check reports.
- `Pkcs7Reader::signers()` now goes through the PEM rather than through a parse,
  because the identity is only in the bytes.
- `Support\NationalRegistry` is new, and bespoke by necessity: neither Laravel
  nor any dependency here validates a CPF (docs/spec/conventions.md).
- **It says a number is well formed, never that it exists.** Whether the Receita
  Federal issued it is a question only the Receita Federal answers, and asking
  would mean a network call from validation, which nothing here does.
- `Enums\Asn1Tag` gained `Context3`, the certificate extensions tag.

## Alternatives rejected

| | Why not |
|---|---|
| Leave it to the application | Everyone writes the same `explode(':')`, and it is wrong for an e-CNPJ |
| Parse the common name only | The CPF there is unstructured text, and an e-CNPJ's names the company |
| Wait for `openssl_x509_parse()` to render otherName | It has not in twenty years, and the DER is right there |
| Check the number against the Receita Federal | A network call from validation, and an availability dependency on somebody else's service |
| Call the structural check "validation" without qualification | It would read as trust, and a self-signed certificate passes it |
| A `CertificatePolicies` check for the A1/A3 arc | Worth having, and it says which kind of certificate rather than whether it is well formed. Left out rather than half done |
