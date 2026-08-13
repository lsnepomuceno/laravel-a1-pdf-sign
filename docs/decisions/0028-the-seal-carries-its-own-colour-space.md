# 0028: The seal carries its own colour space, built rather than vendored

**Status:** implemented.

## Context

[0025](0025-what-signing-does-to-pdf-a.md) measured what signing does to a
PDF/A document and left one thing broken on purpose: **a visible seal cost
conformance, in both parts.**

The seal was embedded as `/DeviceRGB`, and §6.2.3.3 (part 1) and §6.2.4.3
(part 2) allow that only where the file carries an OutputIntent with an RGB
destination profile. So a conformant document went in, a seal was drawn, and
veraPDF failed the result.

The reason it was left is that the obvious fix is not the signer's to make. An
OutputIntent declares the intended output device **for the whole document**.
That is the author's statement about their own file, not something to add on the
way past while appending a revision (invariant 2).

An `/ICCBased` colour space is the alternative: it carries its profile with it,
so it is conformant whatever the document declares, and it asks the document for
nothing. That needs a profile to carry, and **embedding a third party's binary
raises a licensing question in an MIT package.** 0025 named that as the next
step rather than deciding it quietly inside a measurement commit.

## Decision

`Support\SrgbProfile` builds an sRGB ICC profile from published numbers.

The primaries, white point and transfer function are IEC 61966-2-1. The file
format is ICC.1:2001-04. Both are public specifications, so this is the same
clean-room reasoning invariant 1 applies to ISO 32000-1: **the technique is
studied, the bytes are not copied.** No profile is vendored, no licence is
inherited, and there is no third-party binary in the repository to audit.

The profile is 2604 bytes and deterministic. It computes the D50-adapted
colorants from the chromaticities by ordinary colorimetry, and the Bradford
adaptation from the published cone-response matrix.

### The arithmetic is checked against the published profile

A structurally valid profile describing the *wrong* primaries would pass every
conformance check and render the seal in the wrong colour. veraPDF cannot catch
that: it validates the container, not the colours.

So `tests/Support/SrgbProfileTest.php` asserts the computed colorants against the values
every sRGB profile in circulation carries:

| | Computed | Published |
|---|---|---|
| rXYZ | 0.4360 0.2225 0.0139 | the same |
| gXYZ | 0.3851 0.7169 0.0971 | the same |
| bXYZ | 0.1431 0.0606 0.7139 | 0.7141 |
| chad | 1.0479 0.0229 -0.0502 … | exact, to every printed digit |

The two ten-thousandths on blue are the published Bradford coefficients' own
precision, not an error in the derivation. The tolerance is half a thousandth.

### The page gets a transparency group, when it needs one

Fixing the colour space left PDF/A-2 with a transparent seal failing on one
rule, and veraPDF named it by number: **§6.2.10**, a page carrying transparency
needs a `/Group` whose `/CS` gives the blending colour space, unless an
OutputIntent answers for it.

The group is written only when the seal is actually transparent, and only when
the page does not already have one. A producer that chose a blending space
chose it; overruling that is not the signer's business either.

## Consequences

Every cell 0025 measured as failing now passes, except the one ISO forbids:

| | 0025 | Now |
|---|---|---|
| PDF/A-1b, opaque seal | FAIL | **PASS** |
| PDF/A-1b, transparent seal | FAIL | FAIL, permanently |
| PDF/A-2b, opaque seal | FAIL | **PASS** |
| PDF/A-2b, transparent seal | FAIL | **PASS** |

- **A visible seal no longer costs PDF/A conformance.** That was the whole point,
  and it is now a supported claim rather than a documented limitation.
- **PDF/A-1 with a transparent seal is still impossible**, and always will be:
  §6.4 forbids `/SMask` outright ([0023](0023-a-seal-that-can-be-transparent.md)).
  `seal.transparent => false` remains the lever.
- **Every sealed document grows by about 2.4 KB**, the deflated profile. It
  compresses poorly because a 1024-point curve of 16-bit samples is close to
  incompressible. Against a seal image that is typically 15 KB to 20 KB, that
  was judged worth an unambiguous colour.
- **An invisible signature embeds no profile.** There is nothing to draw, so
  paying 2.4 KB for a colour nobody sees would be cost without benefit.
- The three tests 0025 left as tripwires are inverted, which is the act of
  adopting this. One of them said, in a comment, that the day it flipped was the
  day someone should be told.
- `Support\SrgbProfile` is `@internal`. It exists to serve the seal, and
  publishing it would be committing to an ICC builder as public surface.

## Alternatives rejected

| | Why not |
|---|---|
| Vendor an sRGB profile from color.org or Argyll | A third party's binary in an MIT package, with its own licence to establish |
| Take the profile path from configuration | Moves a licensing question onto the user as a setting nobody will fill in, and leaves the default broken |
| Add an OutputIntent to the document | It declares the output device for the whole file. The author's statement, not the signer's, and 0025 already rejected it |
| A single-gamma `curv` tag instead of the table | Saves 2 KB and stops being the curve IEC 61966-2-1 specifies. An approximation of a colour is what this record exists to avoid |
| Keep `/DeviceRGB` and document the limitation | It was documented, and it was still a conformant document going in and a non-conformant one coming out |
| Write the transparency group unconditionally | On an opaque seal it would be a claim about the page that signing did not make true |
