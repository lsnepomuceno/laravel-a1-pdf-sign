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
 * as they are: no decode and re-encode.
 *
 * @internal
 */
final class SealAppearance
{
    /**
     * The image XObject holding the rendered seal.
     *
     * @param  ?int  $maskNumber  The /SMask object, when the seal is
     *                            transparent. §8.9.5.4: the alpha channel is a
     *                            separate greyscale image, not a fourth
     *                            component of this one.
     */
    public function imageObject(int $number, SealImage $seal, ?int $maskNumber = null, ?int $profileNumber = null): string
    {
        $mask = $maskNumber !== null && $seal->isTransparent() ? "/SMask {$maskNumber} 0 R" : '';

        // /DeviceRGB is what the samples are, but PDF/A allows it only where the
        // file carries an RGB OutputIntent, which is the author's statement
        // about their document and not the signer's to add. An /ICCBased space
        // carries its own profile and needs no such declaration
        // (docs/decisions/0025-what-signing-does-to-pdf-a.md).
        $colourSpace = $profileNumber === null ? '/DeviceRGB' : "[/ICCBased {$profileNumber} 0 R]";

        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Image'
            . "/Width {$seal->width}/Height {$seal->height}"
            . "/ColorSpace{$colourSpace}/BitsPerComponent 8"
            . '/Filter/' . $seal->pdfFilter()
            . $mask
            . '/Length ' . strlen($seal->contents)
            . ">>\nstream\n"
            . $seal->contents
            . "\nendstream\nendobj\n";
    }

    /**
     * The soft mask carrying the seal's alpha channel.
     *
     * One component per pixel in DeviceGray, where 0 is fully transparent and
     * 255 fully opaque, which is the same convention PNG's alpha uses, so the
     * samples go in as they came out.
     */
    public function maskObject(int $number, SealImage $seal): string
    {
        $alpha = (string) $seal->alpha;

        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Image'
            . "/Width {$seal->width}/Height {$seal->height}"
            . '/ColorSpace/DeviceGray/BitsPerComponent 8'
            . '/Filter/FlateDecode'
            . '/Length ' . strlen($alpha)
            . ">>\nstream\n"
            . $alpha
            . "\nendstream\nendobj\n";
    }

    /**
     * The ICC profile the seal's colour space points at.
     *
     * Deflated, because it is 2.6 KB of tables that compress to well under half
     * that, and every signed document carries one.
     */
    public function profileObject(int $number, string $profile): string
    {
        $deflated = (string) gzcompress($profile, 9);

        return "{$number} 0 obj\n"
            . '<</N 3'
            . '/Filter/FlateDecode'
            . '/Length ' . strlen($deflated)
            . ">>\nstream\n"
            . $deflated
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
     * An appearance that draws nothing, for an invisible signature.
     *
     * ISO 19005-1 §6.9 requires every form field to have an appearance
     * dictionary, and a signature with no seal is still a form field. A zero
     * box draws nothing, which is what invisible means, while giving the field
     * the appearance the standard asks for
     * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
     */
    public function emptyForm(int $number): string
    {
        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Form'
            . '/BBox[0 0 0 0]'
            . '/Resources<<>>'
            . '/Length 0'
            . ">>\nstream\n\nendstream\nendobj\n";
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
