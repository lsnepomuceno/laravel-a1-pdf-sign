# 0033: The seal honours the page's rotation

**Status:** implemented.

## Context

`/Rotate` turns a page clockwise for display and leaves its coordinate system
alone (ISO 32000-1 §7.7.3.3). `SealPlacement` is documented in terms of where
the seal appears, and the caller giving those coordinates is looking at the
document in a reader, where it is already turned.

The two were never reconciled. The placement went straight into `/Rect`, and
`grep -rn "Rotate" src/` returned nothing at all: the key was read nowhere.

Measured on a page carrying `/Rotate 90`, with a placement of (60, 400):

```
Rect[60 400 180 460]
```

The caller's numbers, untouched. On screen that is somewhere else entirely, the
seal reads sideways, and on a page rotated 90 or 270 it can fall outside the
visible area, since the displayed width and height have swapped.

`/Rotate 90` is how most scanners and many generators express landscape. This
was not an exotic input.

## Decision

**The placement is where the seal appears, and the file records where that is.**

Two things follow, and only doing the first would be worse than doing neither:
the seal would land correctly and read sideways.

- `/Rect` is mapped from displayed coordinates into user space. For a quarter
  turn clockwise the user-space origin, the bottom left, is displayed at the top
  left, so a displayed point (x, y) sits at user (width − y, x).
- The appearance carries a `/Matrix` turning it the other way, because a form
  XObject is drawn in user space and the display rotation applies to it too. No
  translation is needed: the reader maps the transformed bounding box onto
  `/Rect` (§12.5.5).

`/Rotate` and `/MediaBox` are read **with inheritance**, through `/Parent`. Both
are inheritable (§7.7.3.4, Table 30), and one declaration on `/Pages` is the
ordinary way to say "this document is landscape". Reading the page object alone
would have missed the common case.

**Geometry is per page**, not per document. A file can carry a landscape scan
beside a portrait cover, and `SealPlacement` can put a stamp on both.

## Verification

The rectangle is asserted at every rotation, including 360 and −90, which
normalise to no turn and to 270.

The claim that matters is about what a reader shows, so it was checked with one.
Both documents rendered with poppler's `pdftoppm` at 40 dpi, and the ink located:

| | Asked for, as displayed | Rendered |
|---|---|---|
| No rotation | x 10-30%, y 45-52% | x 10-30%, y 45-51% |
| `/Rotate 90` | x 7-21%, y 23-33% | x 7-21%, y 24-33% |

The rotated page's ink also spans 120 pt across and 60 pt down, so the seal is
neither distorted nor turned on screen.

## Consequences

- **A document with no rotation produces the bytes it did before.** The mapping
  returns its input and no `/Matrix` is written, so every committed sample and
  every conformance verdict measured against them is unaffected.
- A page with no `/MediaBox` anywhere above it behaves as unrotated. The mapping
  needs the page's size and inventing one would put the seal somewhere
  arbitrary; landing where it used to is at least predictable.
- `Signing\Incremental\PageGeometry` is where the mapping lives, so the
  arithmetic is testable without signing anything.

## Alternatives rejected

| | Why not |
|---|---|
| Treat the placement as user space and let the caller compensate | The caller is looking at a reader. The package is the only party that knows the page is turned |
| `/MK <</R 90>>` on the widget | Readers vary in whether they honour it. A matrix on the appearance is what the rendering algorithm is specified to apply |
| Read `/Rotate` from the page only | Misses the document declared landscape once on `/Pages`, which is the common shape |
| Fix the rectangle and leave the appearance | The seal lands correctly and reads sideways, which looks like a different bug |
