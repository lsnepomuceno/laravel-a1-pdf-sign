# 0013: Signing into a field the document already carries

**Status:** implemented.

## Context

`Signing\Incremental\RevisionWriter` always creates the signature field it signs
into: a fresh `/Type /Annot /Subtype /Widget /FT /Sig` with the name it was
given, added to `/AcroForm`. `fieldName()` chooses that name, and unnamed
signatures get `Signature1`, `Signature2` and so on.

That covers the case where the application owns the document. It does not cover
the case where the document arrives with its fields already placed:

> a contract template laid out by the legal team, carrying empty
> `SignatureManager` and `SignatureEmployee` fields, where the application is
> expected to fill the right one.

Today the package appends a new field beside the empty one, so the document ends
up with a signature that is valid and in the wrong place, plus an unfilled field
that was the point of the template.

## Decision

`fieldName()` gains a sibling that targets rather than names:

```php
->intoField('SignatureManager')
```

When the named field exists and is an unsigned `/FT /Sig` widget, the signature
fills it: the widget keeps its rectangle, its page and its appearance, and gains
`/V` pointing at the new signature dictionary. When it does not exist, or exists
and is already signed, that is an error rather than a silent fallback to
appending. Falling back would reproduce exactly the failure this exists to
prevent, and would do it quietly.

The existing `/AcroForm` is updated in place through the revision rather than
extended with a new field, which is the part that differs most from what the
writer does today.

## Consequences

- The seal interacts with this. A pre-placed field has its own rectangle, so a
  `SealPlacement` passed alongside `intoField()` is a contradiction: one of them
  has to win, and the field's own geometry is the reason the caller chose the
  field. Passing both is rejected rather than resolved by precedence.
- Discovery is exposed on its own, since an application that fills fields
  usually needs to list them first.

## What was built

```php
A1PdfSign::signatureFields($path);   // list<SignatureField>, in /Fields order

A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($template)
    ->intoField('SignatureManager')
    ->seal()
    ->sign();
```

`Signing\Incremental\SignatureFieldReader` walks the catalog's `/AcroForm`
`/Fields`, ISO 32000-1 §12.7.2, rather than scanning the file for `/FT /Sig`. A
widget not registered on the form is not a form field, and filling it would
leave the form still saying the document has an empty one.

`RevisionWriter` then keeps the widget's **own object number** and replaces that
object, adding `/V`. Everything the template put there survives: the rectangle,
the page reference, the flags. An `/AP` already on the field, the "sign here"
graphic an empty field usually ships with, is replaced by the seal.

Three refusals, none of which falls back to appending:

| | |
|---|---|
| The field does not exist | names the ones that do, since a misspelling is the usual cause |
| The field is already signed | filling it again would replace that signature rather than add one |
| A placement was given as well | the field already has a rectangle, and resolving by precedence would silently move the seal off the box the template drew |

### Two things the revision does not write

A field the document already carries is already on the form and on its page, so
the catalog is rewritten only when `/SigFlags` is missing and the page only when
the widget is absent from `/Annots`. Emitting them unchanged would grow every
revision with two objects that decide nothing.

### The case that is honoured rather than refused

A field with a zero rectangle keeps the signature invisible even when `seal()`
was called. That is the template's statement about visibility, and `intoField()`
means honour the template. It is stated here because it is the one place the
feature does something other than what the caller literally asked for.

## Two forms of /AcroForm, both of which occur

Acrobat writes the dictionary inline and nests `/DR` inside it, so a non-greedy
match to the first `>>` stops before `/Fields` is reached; the reader counts
depth. Other producers write `/AcroForm` as an indirect reference, which has no
dictionary at the catalog at all and has to be followed. Handling only the first
form reports a real template as carrying no fields.

## Verified

`poc/sign-into-field.php` produces the artefact; `pdfsig` reads it. The template
carries `SignatureManager` and `SignatureEmployee`, and the employee is signed
first, deliberately: filling them out of order is what catches a writer that
takes the next empty field rather than the one named.

```
Signature #1:  Signature Field Name: SignatureManager
Signature #2:  Signature Field Name: SignatureEmployee
```

Poppler reports the template's own names rather than `Signature1` and
`Signature2`, and the document still carries **exactly two** fields. A third
would be the failure this record exists to prevent. Rendered at 100 dpi, each
seal sits inside its own field's rectangle, at the two positions the template
declared.

`samples/signed-into-fields.pdf` is the committed artefact.

## The fixture

`tests/Resources/signature-fields.pdf` is committed, hand-built and 891 bytes: a
one-page document with two empty signature fields, a font resource and a content
stream whose `/Length` is right. The first draft had neither, and poppler said
so on the first render; a fixture that a reader complains about is not a fixture
a test can rely on.

Structural cases, an `/AcroForm` held by reference and one with a nested `/DR`,
are built synthetically by `pdfWith()` in `tests/Pest.php` rather than patched
into that file. Editing a dictionary's length shifts every offset after it, and
the cross-reference table would then point into the middle of objects: a test
reading the wrong bytes can still pass, which is the worst kind.
