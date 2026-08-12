<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Signing\Encryption\ObjectCipher;

/**
 * Builds the objects that make a signature visible.
 *
 * A visible signature is a widget whose /AP points at a form XObject, which in
 * turn draws an image XObject (ISO 32000-1 §12.5.5 and §12.7.4.5). An opaque
 * seal is embedded through /DCTDecode, so the JPEG bytes Intervention produced
 * are stored as they are, with no decode and re-encode; a transparent one is
 * deflated samples plus an /SMask, because PDF has no PNG filter
 * (docs/decisions/0023-a-seal-that-can-be-transparent.md).
 *
 * Every stream here goes through an `ObjectCipher`, which does nothing at all
 * for an ordinary document and encrypts for a document that is itself
 * encrypted (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
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
     * @param  ?int  $profileNumber  The /ICCBased profile the colour space
     *                               points at, so the seal carries its own
     *                               colour rather than asking the document for
     *                               an OutputIntent
     *                               (docs/decisions/0028-the-seal-carries-its-own-colour-space.md).
     * @param  ?ObjectCipher  $cipher  Null for an ordinary document, which is
     *                                 the same as an inactive one.
     */
    public function imageObject(
        int $number,
        SealImage $seal,
        ?int $maskNumber = null,
        ?int $profileNumber = null,
        ?ObjectCipher $cipher = null,
    ): string {
        [$contents, $length] = ($cipher ?? new ObjectCipher())->stream($seal->contents, $number);

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
            . '/Length ' . $length
            . ">>\nstream\n"
            . $contents
            . "\nendstream\nendobj\n";
    }

    /**
     * The soft mask carrying the seal's alpha channel.
     *
     * One component per pixel in DeviceGray, where 0 is fully transparent and
     * 255 fully opaque, which is the same convention PNG's alpha uses, so the
     * samples go in as they came out.
     */
    public function maskObject(int $number, SealImage $seal, ?ObjectCipher $cipher = null): string
    {
        [$alpha, $length] = ($cipher ?? new ObjectCipher())->stream((string) $seal->alpha, $number);

        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Image'
            . "/Width {$seal->width}/Height {$seal->height}"
            . '/ColorSpace/DeviceGray/BitsPerComponent 8'
            . '/Filter/FlateDecode'
            . '/Length ' . $length
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
    public function profileObject(int $number, string $profile, ?ObjectCipher $cipher = null): string
    {
        [$deflated, $length] = ($cipher ?? new ObjectCipher())->stream((string) gzcompress($profile, 9), $number);

        return "{$number} 0 obj\n"
            . '<</N 3'
            . '/Filter/FlateDecode'
            . '/Length ' . $length
            . ">>\nstream\n"
            . $deflated
            . "\nendstream\nendobj\n";
    }

    /**
     * The form XObject the widget renders, drawing the image to fill it.
     */
    public function formObject(
        int $number,
        int $imageNumber,
        SealPlacement $placement,
        SealImage $seal,
        ?ObjectCipher $cipher = null,
        ?PageGeometry $geometry = null,
    ): string {
        [$width, $height] = $this->size($placement, $seal);

        // q/Q brackets the graphics state; cm scales the unit square the image
        // is drawn into up to the box.
        [$stream, $length] = ($cipher ?? new ObjectCipher())->stream("q {$width} 0 0 {$height} 0 0 cm /Im0 Do Q", $number);

        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Form'
            . "/BBox[0 0 {$width} {$height}]"
            // Turned the other way, so the seal reads upright once the reader
            // applies the page's own rotation to it.
            . ($geometry ?? new PageGeometry())->appearanceMatrix()
            . "/Resources<</XObject<</Im0 {$imageNumber} 0 R>>>>"
            . '/Length ' . $length
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
    public function emptyForm(int $number, ?ObjectCipher $cipher = null): string
    {
        [$stream, $length] = ($cipher ?? new ObjectCipher())->stream('', $number);

        return "{$number} 0 obj\n"
            . '<</Type/XObject/Subtype/Form'
            . '/BBox[0 0 0 0]'
            . '/Resources<<>>'
            . '/Length ' . $length
            . ">>\nstream\n" . $stream . "\nendstream\nendobj\n";
    }

    /**
     * The rectangle the widget occupies, in PDF user space.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function rectangle(SealPlacement $placement, SealImage $seal, ?PageGeometry $geometry = null): array
    {
        [$width, $height] = $this->size($placement, $seal);

        // The placement is where the seal appears, and on a rotated page that
        // is not where its coordinates are (docs/decisions/0033-the-seal-honours-page-rotation.md).
        return ($geometry ?? new PageGeometry())
            ->toUserSpace($placement->x, $placement->y, $width, $height);
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
