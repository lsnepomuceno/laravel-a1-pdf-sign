<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

/**
 * A page as the reader displays it, against a page as its coordinates describe
 * it.
 *
 * *ISO 32000-1 §7.7.3.3: /Rotate is the number of degrees by which the page
 * shall be rotated clockwise when displayed.* The coordinate system does not
 * turn with it, so on a page carrying `/Rotate 90` a rectangle at the bottom
 * left of user space appears at the top left of the screen, and anything drawn
 * in it reads sideways.
 *
 * That mattered here because `SealPlacement` is documented in terms of where
 * the seal appears, and the caller is looking at the document in a reader.
 * Before this existed the placement was written straight into `/Rect`, so a
 * seal asked for at (60, 400) on a landscape scan, which is `/Rotate 90` in
 * practice, landed somewhere else entirely and could fall outside the visible
 * area, since the displayed width and height have swapped.
 *
 * A page with no rotation returns its input unchanged and needs no matrix, so
 * every document that is not rotated produces exactly the bytes it did before.
 */
final readonly class PageGeometry
{
    public function __construct(
        public int $rotation = 0,
        public float $width = 0.0,
        public float $height = 0.0,
    ) {}

    /**
     * Reads the two keys, normalising the rotation.
     *
     * Values are multiples of 90 and may be negative or above 360, so 450 and
     * -270 are both a quarter turn clockwise.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $mediaBox
     */
    public static function of(int $rotate, ?array $mediaBox): self
    {
        $rotation = ((int) round($rotate / 90) * 90 % 360 + 360) % 360;

        if ($mediaBox === null) {
            // Without a media box the mapping below cannot be computed, and
            // guessing a page size would put the seal somewhere arbitrary.
            // Behaving as an unrotated page is the honest failure: the seal
            // lands where it used to, which is at least predictable.
            return new self();
        }

        return new self(
            $rotation,
            abs($mediaBox[2] - $mediaBox[0]),
            abs($mediaBox[3] - $mediaBox[1]),
        );
    }

    public function isRotated(): bool
    {
        return $this->rotation !== 0 && $this->width > 0.0 && $this->height > 0.0;
    }

    /**
     * The rectangle in user space for a box the caller placed on the screen.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function toUserSpace(float $x, float $y, float $width, float $height): array
    {
        if (! $this->isRotated()) {
            return [$x, $y, $x + $width, $y + $height];
        }

        // Each case maps the two opposite corners and then normalises, because
        // a rotation can put the "lower left" corner above the other one and a
        // /Rect with its coordinates the wrong way round is a rectangle readers
        // disagree about.
        [$ax, $ay] = $this->corner($x, $y);
        [$bx, $by] = $this->corner($x + $width, $y + $height);

        return [min($ax, $bx), min($ay, $by), max($ax, $bx), max($ay, $by)];
    }

    /**
     * The /Matrix a form XObject needs to render upright once the page is
     * turned.
     *
     * The appearance is drawn in user space, so the display rotation applies to
     * it as well. Rotating it the other way in advance is what leaves it
     * readable. Null when there is nothing to correct.
     *
     * The reader maps the transformed bounding box onto /Rect (§12.5.5), so no
     * translation is needed here: only the rotation.
     */
    public function appearanceMatrix(): ?string
    {
        if (! $this->isRotated()) {
            return null;
        }

        return match ($this->rotation) {
            90 => '/Matrix[0 1 -1 0 0 0]',
            180 => '/Matrix[-1 0 0 -1 0 0]',
            270 => '/Matrix[0 -1 1 0 0 0]',
            default => null,
        };
    }

    /**
     * One corner, from what the viewer sees to what the file records.
     *
     * Derived rather than guessed. For a quarter turn clockwise the user-space
     * origin, the bottom left of the page, is displayed at the top left, so a
     * displayed point (dx, dy) is at user (width - dy, dx).
     *
     * @return array{0: float, 1: float}
     */
    private function corner(float $x, float $y): array
    {
        return match ($this->rotation) {
            90 => [$this->width - $y, $x],
            180 => [$this->width - $x, $this->height - $y],
            270 => [$y, $this->height - $x],
            default => [$x, $y],
        };
    }
}
