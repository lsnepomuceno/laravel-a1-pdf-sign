# Signed samples

One signed PDF per signature profile, plus a document carrying six signatures.
They exist so a change to the signing engine can be checked against real
readers (Adobe Reader, ITI Validar, poppler's `pdfsig`) and not only against
this package's own validator, which shares its assumptions with the code it
validates.

Regenerate them after any change to `src/Signing/`:

```bash
docker compose -f .docker/compose.yaml run --rm php php poc/sign-samples.php
```

The script writes to `.output/`; copy what you need over the files here.

## The certificate is untrusted, and that is expected

`certificate.pfx` is the throwaway self-signed certificate the test suite
generates. Its password is:

```
example's password with special chars: $ & * ? " '
```

`certificate.pem` is **the same certificate**, not a second one: same serial,
same subject, same password. It is here so the PEM entry point can be exercised
against the identity the rest of this directory already uses:

```php
A1PdfSign::newSignature()
    ->certificatePem('samples/certificate.pem', password: $password)
    ->pdf($path)
    ->sign();
```

Its private key is encrypted under that same password. A PEM key is frequently
shipped unencrypted, and this sample deliberately does not model that.

**Every reader will report the signer as untrusted.** That is the certificate's
provenance, not the signature's integrity: it is self-signed and chains to
nothing. Everything else validates normally: document hash, sub-filter,
timestamp token, and the coverage of each signature.

To make Adobe Reader report the signer as trusted, import `certificate.pfx` and
add it as a trusted identity for signature validation. ITI Validar will always
reject it, since it only trusts the ICP-Brasil chain; testing there needs a real
ICP-Brasil certificate.

## The files

| File | What it carries |
|---|---|
| `legacy.pdf` | `/SubFilter adbe.pkcs7.detached`, ISO 32000-1, widest reader support |
| `pades-b-b.pdf` | PAdES B-B, `ETSI.CAdES.detached` with the ESS `signing-certificate-v2` attribute |
| `pades-b-t.pdf` | B-B plus an RFC 3161 token from freetsa.org |
| `pades-b-lt.pdf` | B-T plus a Document Security Store |
| `pades-b-lta.pdf` | B-LT plus an archive timestamp, a second `/ByteRange`, of type `ETSI.RFC3161`, covering the whole file |
| `six-signatures.pdf` | Six signatures on one document |
| `two-seals.pdf` | Two signatures, each with its own visible seal in its own place |
| `xref-stream.pdf` | Two signatures on a PDF 1.5 document whose cross-reference sections are streams, not tables |
| `signed-into-fields.pdf` | A template's own two signature fields, filled by name rather than appended beside |
| `certified.pdf` | A certification at `form-filling`, then an approval signature on top of it |
| `object-stream.pdf` | Two signatures on a document whose catalog and pages are packed into an object stream |

There is no `pem-signed.pdf`, on purpose. The encoding only changes how the key
is loaded, so a document signed through `certificatePem()` is indistinguishable
from `pades-b-b.pdf`, since a separate sample would imply a distinction that does not
exist. `poc/sign-samples.php` signs one anyway and validates it, which is where
the two entry points are shown to converge on real output.

## What `object-stream.pdf` proves

That a document whose **catalog is packed into an object stream** can be signed.
PDF 1.5 has two compression structures, not one: the cross-reference stream that
indexes objects and the object stream that packs them. Word and "print to PDF"
in Chrome emit both, and dictionaries such as the catalog and the page are
exactly what gets packed.

2.2 read the index and still refused these documents, because signing rewrites
the catalog to register the signature field and a catalog it cannot read is a
document it cannot sign. That is why this sample exists separately from
`xref-stream.pdf`: they look like the same capability and are not.

Nothing is unpacked in place. The revision writes the changed objects back at
the top level, uncompressed, and the newer cross-reference entry supersedes the
packed one. The original bytes survive and the packed copy stays in the file as
history.

See [`../docs/decisions/0015-object-streams.md`](../docs/decisions/0015-object-streams.md).

## What `certified.pdf` proves

That a `/DocMDP` certification is written and survives a later signature. The
author certifies at `form-filling`, ISO 32000-1 §12.8.2.2, and a second party
then signs: that combination is the whole reason levels 2 and 3 exist, since
`no-changes` would forbid the very revision the second signature needs.

**Poppler confirms this file, and cannot confirm the certification.** Opened in
Okular, both signatures report as cryptographically valid with the right field
names and reasons, the form is reachable, and the approval signature applied
after the certification is accepted rather than flagged as a violation of it.
That last point was a real risk and is now settled.

What poppler cannot answer is whether it would *enforce* the transform, since
`pdfsig` does not surface `/DocMDP` at all. It was asked indirectly instead:
`poc/certify-fillable.php` certifies one document twice, at `no-changes` and at
`form-filling`, differing in nothing but the permission, and a reader that
enforces the transform has to refuse typing in the first and allow it in the
second. **Both allow it, identically. Poppler does not enforce `/DocMDP`.**

That is a fact about poppler rather than about these bytes, and it means the
enforcement path can only be exercised in Adobe Reader or ITI Validar. Open this
file in one if you have it.

The structure, for reading by hand:

```
/Perms<</DocMDP 19 0 R>>
19 0 obj <</Type/Sig ... /Reference[<</Type/SigRef/TransformMethod/DocMDP
                                     /TransformParams<</Type/TransformParams/P 2/V/1.2>>>>]
```

See [`../docs/decisions/0012-certification-signatures.md`](../docs/decisions/0012-certification-signatures.md).

## What `signed-into-fields.pdf` proves

That `intoField()` fills the field it was told to. The source is a template with
an empty `SignatureManager` and an empty `SignatureEmployee`, and the employee
signs first: filling them out of order is what catches a writer that takes "the
next empty one" rather than the one named.

Open it and check two things. Poppler reports the signatures under the
template's own names, not `Signature1` and `Signature2`:

```
Signature #1:  Signature Field Name: SignatureManager
Signature #2:  Signature Field Name: SignatureEmployee
```

And the document still carries exactly **two** fields. A third would mean a
field was appended beside the one asked for, which is the failure the feature
exists to stop: a signature that is valid and in the wrong place, with the
template's field still empty. Each seal is drawn into its field's own
rectangle, so they sit where the template put them rather than at the
configured default placement.

See [`../docs/decisions/0013-signing-into-an-existing-field.md`](../docs/decisions/0013-signing-into-an-existing-field.md).

## What `xref-stream.pdf` proves

That a document using the cross-reference stream of ISO 32000-1 §7.5.8 can be
signed, and signed again. PDF 1.5 is from 2003 and this is the form Word,
"print to PDF" in Chrome and most modern generators emit, so it is not an edge
case: it is the majority of documents a consumer holds.

**This is the sample that has to be opened rather than trusted.** The suite
cannot tell "a revision was appended" from "a revision was appended in a shape
readers accept". The first attempt appended a classic table to a document whose
latest section was a stream, and poppler answered:

```
File 'xref-signed.pdf' does not contain any signatures
```

The bytes were there; nothing read them as a signature. The signer now appends
a stream when the document already uses one, and the appended stream indexes
itself, since the next revision can only find this one's objects through it.
See [`../docs/decisions/0009-cross-reference-streams.md`](../docs/decisions/0009-cross-reference-streams.md).

## What `two-seals.pdf` proves

That a seal belongs to one signature and not to the document. The first
signature carries a seal rendered from the certificate, at x 150 / y 240; the
second carries `src/Resources/img/sign-seal.png`, supplied through
`sealFrom()`, at x 30 / y 60. Open it in any reader and both are visible, in
different places.

Nothing shares state between the two: `newSignature()` is bound with `bind()`
rather than `singleton()`, and each revision emits its own image and form
XObject, so the file holds two of each rather than one pair reused twice.

Verified with poppler `pdfsig` 25.12: both report *Signature is Valid*, with
the coverage the revisions imply.

```
#1  [0 - 9190],  [25576 - 42777]   Not total document signed
#2  [0 - 42906], [59292 - 76506]   Total document signed
```

The first covers the file as it stood at its own revision, which is why poppler
says the document is not signed in full: the second signature came after it.
That is the expected reading, not a defect.

## What `six-signatures.pdf` proves

This is the case [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430)
could not produce. In v1 each new signature rebuilt the document through FPDI
and discarded the previous one; here each signature is an appended revision, so
all six coexist.

Verified with poppler `pdfsig` 25.12: all six report *Signature is Valid*, with
progressive coverage:

```
#1  [0 - 9190],  [25576 - 26301]
#2  [0 - 26430], [42816 - 43556]
#3  [0 - 43685], [60071 - 60825]
#4  [0 - 60954], [77340 - 78108]
#5  [0 - 78237], [94623 - 95405]
#6  [0 - 95534], [111920 - 112717]   <- "Total document signed"
```

Each signature covers the file as it stood when that signature was made, which
is why `pdfsig` reports "Not total document signed" for #1 to #5. That is
correct, and it is how a reader knows what each signer actually saw.

Checking a sample yourself:

```bash
pdfsig samples/six-signatures.pdf
```
