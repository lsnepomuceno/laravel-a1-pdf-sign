<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;

/**
 * Builds the objects that make a signature visible.
 *
 * A visible signature is a widget whose /AP points at a form XObject, which in
 * turn draws an image XObject (ISO 32000-1 §12.5.5 and §12.7.4.5). The JPEG is
 * embedded through /DCTDecode, so the bytes Intervention produced are stored
 * as they are — no decode and re-encode.
 *
 * @internal
 */
final class SealAppearance
{
    /**
     * The image XObject holding the rendered seal.
     */
    public function imageObject(int $number, SealImage $seal): string
    {
        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Image'
            . "/Width {$seal->width}/Height {$seal->height}"
            . '/ColorSpace/DeviceRGB/BitsPerComponent 8'
            . '/Filter/' . $seal->pdfFilter()
            . '/Length ' . strlen($seal->contents)
            . ">>\nstream\n"
            . $seal->contents
            . "\nendstream\nendobj\n";
    }

    /**
     * The form XObject the widget renders, drawing the image to fill it.
     */
    public function formObject(int $number, int $imageNumber, SealPlacement $placement, SealImage $seal): string
    {
        [$width, $height] = $this->size($placement, $seal);

        // q/Q brackets the graphics state; cm scales the unit square the image
        // is drawn into up to the box.
        $stream = "q {$width} 0 0 {$height} 0 0 cm /Im0 Do Q";

        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Form'
            . "/BBox[0 0 {$width} {$height}]"
            . "/Resources<</XObject<</Im0 {$imageNumber} 0 R>>>>"
            . '/Length ' . strlen($stream)
            . ">>\nstream\n"
            . $stream
            . "\nendstream\nendobj\n";
    }

    /**
     * The rectangle the widget occupies, in PDF user space.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function rectangle(SealPlacement $placement, SealImage $seal): array
    {
        [$width, $height] = $this->size($placement, $seal);

        return [$placement->x, $placement->y, $placement->x + $width, $placement->y + $height];
    }

    /**
     * A height of zero means "keep the image's aspect ratio", matching the v1
     * behaviour of the v1 placement.
     *
     * @return array{0: float, 1: float}
     */
    private function size(SealPlacement $placement, SealImage $seal): array
    {
        $width = $placement->width;

        $height = $placement->height > 0
            ? $placement->height
            : $width * ($seal->height / max($seal->width, 1));

        return [round($width, 2), round($height, 2)];
    }
}
