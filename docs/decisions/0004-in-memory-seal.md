# 0004: The seal is rendered in memory

**Status:** accepted, implemented. The plan marked this "to be confirmed during
implementation, the imaging bridge may impose restrictions"; it was confirmed.

## Context

v1 generated the visual seal as a file, then read it back to stamp it into the
document. That is an intermediate file, on a path that already had a cleanup
problem ([0003](0003-temporary-files-outside-the-package.md)), for data that
never needed to leave the process.

## Decision

The `SealRenderer` contract returns bytes.
`Seal\InterventionSealRenderer` implements it on Intervention Image v3, and
`Signing\Incremental\SealAppearance` stamps the widget from those bytes.

## Consequences

- No intermediate file between generating the seal and stamping it.
- Everything v1 hard-coded (driver, font path, size, colour, background,
  placement) comes from configuration, with `SealPlacement` carrying position
  and page.
- Omitting `seal()` produces an invisible signature, which is still a valid one:
  the seal is an appearance, not part of the cryptography.
- `sealFrom($path)` skips the renderer for callers who already have an image.
