# 0023: A seal that can be transparent, and say what the caller wants

**Status:** implemented.

## Context

Three things about the seal, and the third is a defect rather than a limit.

**It was always an opaque rectangle.** The renderer encoded to JPEG, and JPEG
has no alpha channel, so the artwork's own transparency was flattened at encode
time. Every seal this package has ever stamped is a solid block sitting on top
of whatever it covers.

**It always said the same three things.** Subject, issuer and optionally the
expiry date, at three baselines fixed in the source as `TEXT_ROWS = [80, 150,
250]`. A seal that has to carry a protocol number, a department or a second
language had nowhere to put it.

**And `sealFrom()` ignored its argument.** It wrote the path onto
`SealPlacement::$imagePath`, and **nothing in `src/` ever read that property**.
A caller who passed their own artwork got a render of the certificate instead.
This is the same shape as the `$page` defect that
[0017](0017-the-seal-goes-where-it-was-asked-for.md) closed: a documented
builder method, shown in the README and on the documentation site, silently
doing something else.

## Decision

### Transparency, by storing samples rather than a file

PDF has no PNG filter. §8.9.5.4 wants raw samples, with the alpha channel as a
**separate greyscale image** in `/SMask`, so a transparent seal is stored as
deflated RGB with a second image object beside it.

`Enums\SealEncoding` names the two forms and owns the filter each takes.

**The decoding is the one already here.** A PNG's IDAT is zlib with the per-row
PNG predictor, which is exactly `/Filter /FlateDecode` with `/DecodeParms
<</Predictor 15 …>>`, so `Support\PdfFilters` undoes it unchanged
([0020](0020-decode-the-filters-documents-use.md)). `Support\PngReader` only
reads IHDR and splits the interleaved samples.

A PNG this cannot separate, a palette or a 16-bit or an interlaced one, falls
back to the opaque JPEG rather than failing: a seal that renders is better than
a refusal, and the only thing lost is the transparency that was never there
before.

### The layout is the caller's

`Data\SealLayout` carries the lines, their baselines, the left edge, the font,
the colour and the background. Every property is optional and null means "use
the configured default", which is the rule the rest of the package follows.

`seal.text.x` and `seal.text.rows` move into configuration, so the two class
constants go: a set of positions is not a lone fact
([0018](0018-prefer-the-platforms-own-constructs.md)).

**A line with no baseline is not drawn.** Stacking it onto the last row would
put two lines of text on top of each other, which reads as a rendering fault
rather than as a caller mistake.

### `sealFrom()` now does what it says

`SealRenderer::fromImage()` embeds the caller's artwork, honouring its
transparency, and draws only what a layout asks for over it. A caller who
supplied their own image did not ask for the certificate's details printed on
top of it.

## Consequences

- **The default seal is now transparent.** `seal.transparent` is `true`, so the
  artwork's alpha is honoured rather than flattened. Set it to `false` for the
  previous opaque rectangle. This changes what every existing seal looks like,
  and it is the fix rather than a preference: the artwork has always had an
  alpha channel and the encoder has always thrown it away.

- **A transparent seal costs more bytes.** 590×295 RGB deflates to about 13 kB
  against roughly 10 kB of JPEG, plus the mask, which is mostly uniform and
  compresses to very little. Four tests that counted image objects now count
  two per seal.

- `Contracts\SealRenderer` gains a parameter on `render()` and a new
  `fromImage()` method, both breaks for implementers, which the Roave check
  reports.

- `Data\SealImage` gains `alpha` and `encoding`, and `isTransparent()`.
  `mimeType` stays and still describes `contents`, but `encoding` is what
  decides how the bytes are stored.

- `SealPlacement::$imagePath` is now read. It was public API that did nothing.

## Alternatives rejected

| | Why not |
|---|---|
| Embed the PNG file directly | PDF has no PNG filter. There is no such thing to embed |
| A fourth colour component instead of an /SMask | §8.9.5.4 keeps them apart, and readers expect the mask |
| Keep JPEG and composite the artwork onto a background colour | That is the opaque rectangle, painted a different colour |
| Leave transparency opt-in | Nobody turns on a flag to get what the artwork already said |
| Refuse a PNG the reader cannot split | The seal still renders opaque, and only the transparency is lost |
| Leave `sealFrom()` alone and document that it renders the certificate | It is documented as doing the opposite, in two places |
