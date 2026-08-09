# 0013: Signing into a field the document already carries

**Status:** proposed.

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

## Decision, proposed

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
  field. Passing both should be rejected rather than resolved by precedence.
- Discovery is worth exposing on its own, since an application that fills fields
  usually needs to list them first. A reader that returns the unsigned signature
  fields of a document is small and useful independently of this.
