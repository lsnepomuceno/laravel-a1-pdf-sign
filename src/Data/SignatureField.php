<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * A signature field the document already carries.
 *
 * Templates arrive with their fields placed: a contract laid out by the legal
 * team, with an empty SignatureManager and an empty SignatureEmployee. This is
 * what an application lists before it decides which one to fill.
 *
 * See docs/decisions/0013-signing-into-an-existing-field.md.
 */
final readonly class SignatureField extends BaseData
{
    /**
     * @param  string  $name  The /T entry, which is how the field is addressed.
     * @param  bool  $isSigned  Whether /V is already set. A signed field cannot
     *                          be filled again: that would replace a signature
     *                          rather than add one.
     * @param  int  $objectNumber  The widget's own object number.
     * @param  int  $pageNumber  The page the widget sits on, from /P. Zero when
     *                           the field declares none.
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rectangle  /Rect,
     *                          in PDF user space from the bottom-left corner.
     */
    public function __construct(
        public string $name,
        public bool $isSigned,
        public int $objectNumber,
        public int $pageNumber,
        public array $rectangle,
    ) {}

    /**
     * Whether the field has an area to draw a seal into.
     *
     * An invisible field is legal and common: a zero rectangle means the
     * signature is cryptographic only, and a seal placed into it would be
     * drawn nowhere.
     */
    public function isVisible(): bool
    {
        return $this->width() > 0 && $this->height() > 0;
    }

    public function width(): float
    {
        return abs($this->rectangle[2] - $this->rectangle[0]);
    }

    public function height(): float
    {
        return abs($this->rectangle[3] - $this->rectangle[1]);
    }

    /**
     * The field's own geometry, expressed as a placement.
     *
     * A pre-placed field already says where the seal goes, which is the reason
     * the caller chose the field: the template drew the box.
     */
    public function placement(): SealPlacement
    {
        return new SealPlacement(
            x: min($this->rectangle[0], $this->rectangle[2]),
            y: min($this->rectangle[1], $this->rectangle[3]),
            width: $this->width(),
            height: $this->height(),
        );
    }
}
