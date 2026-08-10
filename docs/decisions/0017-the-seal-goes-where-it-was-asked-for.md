# 0017: The seal goes where it was asked for

**Status:** implemented.

## Context

`Data\SealPlacement` has carried `$page` and `$onEveryPage` since 2.0. Nothing
read either of them.

Every seal went onto whichever page `DocumentReader::findFirstPage()` happened to
find, and the documentation told callers otherwise:

> Position, size and page of your choosing
>
> `new SealPlacement(x: 155, y: 250, width: 50, page: SealPlacement::LAST_PAGE)`

That example is from the published 2.x documentation, and running it put the seal
on page 1. `appliesTo()` was written to answer exactly this question and had no
caller anywhere in `src/`.

This is the worst class of defect the package can carry. It is not a refusal, not
a crash and not a missing feature that a reader can see is missing: it is a
documented parameter that silently produces a plausible wrong result, and the
wrong result is a signed contract with the signature on the wrong page.

Proven before the fix, on a purpose-built three-page document: `page: 3` produced
a widget whose `/P` named object 3, the **first** page.

## Decision

**The placement decides the page, and the page tree decides the order.**

### Page order comes from the tree, not from object numbers

`DocumentReader::pages()` walks `/Pages` and `/Kids` from the catalog, ISO
32000-1 §7.7.3.2, and returns page object numbers in reading order.

`findFirstPage()` used to scan the cross-reference table in object-number order
and take the first `/Type/Page`. That answer is right only when the producer
numbered its pages in reading order, which nothing requires: a producer may write
the last page first, and any generator that rewrites one page gives it a fresh
number at the end of the file. Object numbers carry no page order at all, so
"page 3" cannot be resolved from them.

The scan survives as the fallback behind `findFirstPage()`, for a document whose
tree cannot be walked. A document with no walkable tree is treated as a document
of one page, which keeps `LAST_PAGE` and `page: 1` both landing on it and makes
`page: 3` say so rather than guess.

The test fixture numbers its pages backwards on purpose. A fixture numbered in
reading order cannot tell a tree walk apart from the scan it replaced.

### The placement is asked page by page

`RevisionWriter` does not read `$placement->page`. It walks the pages and asks
`appliesTo($n, $count)` for each, so `LAST_PAGE` and `onEveryPage` are decided in
the one class that defines them, and the enum-shaped logic has exactly one home.

### Out of range raises

`page: 7` on a three-page document throws `SealPlacementException`.

Clamping to the last page is the quiet answer and quiet is the whole defect. A
caller who asks for page 7 of a three-page contract has made a mistake, and a
signed document with the seal on page 3 looks deliberate.

### `onEveryPage` writes stamp annotations

A signature is **one** form field with **one** widget, so the seal cannot be a
widget on every page: a widget that is not a form field is invalid, and a second
signature field would be a second signature.

The widget goes on the first page the placement accepts; every further page gets
a `/Subtype/Stamp` annotation (§12.5.6.12) whose `/AP` points at the **same** form
XObject. One image object and one form object serve the whole document, so a
ten-page seal embeds the JPEG once rather than ten times.

The stamps are written inside the signature's own revision, so their bytes fall
within `/ByteRange` and the signature covers them like everything else it wrote.
They are not clickable signature widgets: a reader clicking one gets a stamp, and
the signature panel still lists one signature. That is the honest shape of the
feature, and it is how every other implementation does it.

Only pages the placement accepts are rewritten. Rewriting all of them would make
the revision the size of the document and would touch pages an earlier signature
covers.

## Consequences

- **The default seal page changes from the first page to the last.**
  `SealPlacement::$page` has always defaulted to `LAST_PAGE`, so `->seal()` with
  no arguments was already asking for the last page and getting the first. On a
  single-page document nothing moves, which is every fixture in the suite and
  most contracts. On a multi-page document the seal now lands where the DTO has
  always said it would. This is a behaviour change and is called out in the
  release notes rather than buried as a fix.

- `Exceptions\SealPlacementException` is new, and `sign()` can now raise it.

- `Signing\Incremental\DocumentInfo::has()` and `DocumentReader::pages()` are
  new. Both are `@internal`.

- Invisible signatures are unchanged. Without a seal there is no appearance to
  place, so the widget keeps its zero rectangle on the first page.

- `intoField()` is unchanged. A field carries its own `/P`, and passing a
  placement alongside it was already refused
  ([0013](0013-signing-into-an-existing-field.md)), so there is nothing to
  resolve between them.

## Alternatives rejected

| | Why not |
|---|---|
| Clamp an out-of-range page to the last | The same silence, one step smaller |
| Resolve the page inside `RevisionWriter` from `$placement->page` | Two places would then define what `LAST_PAGE` means, and `appliesTo()` would stay dead |
| Keep scanning the cross-reference table, and only add `LAST_PAGE` | It answers "the highest-numbered page object", which is not the last page |
| Remove `$page` and `$onEveryPage` as dead API | They are documented, and the documentation is the promise |
| A second signature field per page for `onEveryPage` | Each would be a separate signature. The caller asked for one signature shown in several places |
| Copy the image XObject per page | The JPEG once per page, for an appearance that is identical on all of them |
