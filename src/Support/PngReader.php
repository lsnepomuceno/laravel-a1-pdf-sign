<?php

namespace LSNepomuceno\LaravelA1PdfSign\Support;

/**
 * Splits a PNG into the colour and alpha planes a PDF image XObject needs.
 *
 * PDF has no PNG filter, so a transparent seal cannot be embedded as the file
 * the renderer produced: §8.9.5.4 wants raw samples, with the alpha channel as
 * a separate greyscale image in /SMask.
 *
 * **The decoding is the one already here.** A PNG's IDAT is zlib with the
 * per-row PNG predictor, which is exactly `/Filter /FlateDecode` with
 * `/DecodeParms <</Predictor 15 …>>`, so `PdfFilters` undoes it unchanged
 * ([0020](../../docs/decisions/0020-decode-the-filters-documents-use.md)). The
 * only new work is reading IHDR and splitting the interleaved samples.
 *
 * See docs/decisions/0023-a-seal-that-can-be-transparent.md.
 *
 * @internal
 */
final readonly class PngReader
{
    /** The eight bytes every PNG starts with, RFC 2083 §3.1. */
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private PdfFilters $filters = new PdfFilters(),
    ) {}

    /**
     * The image as separated planes, or null when it is a PNG this does not read.
     *
     * Null rather than a guess for a palette, a 16-bit or an interlaced image:
     * each needs work of its own, and producing plausible wrong pixels is worse
     * than saying the seal cannot be transparent.
     *
     * @return array{width: int, height: int, rgb: string, alpha: ?string}|null
     */
    public function planes(string $png): ?array
    {
        $header = $this->header($png);

        if ($header === null) {
            return null;
        }

        [$width, $height, $colours] = [$header['width'], $header['height'], $header['colours']];

        $samples = $this->filters->decode($this->pixelData($png), sprintf(
            '<</Filter/FlateDecode/DecodeParms<</Predictor 15/Colors %d/BitsPerComponent 8/Columns %d>>>>',
            $colours,
            $width,
        ));

        if ($samples === null || strlen($samples) < $width * $height * $colours) {
            return null;
        }

        if ($colours === 3) {
            return ['width' => $width, 'height' => $height, 'rgb' => $samples, 'alpha' => null];
        }

        [$rgb, $alpha] = $this->split($samples, $width * $height);

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb, 'alpha' => $alpha];
    }

    /**
     * IHDR, RFC 2083 §4.1.1, and the reasons to stop.
     *
     * @return array{width: int, height: int, colours: int}|null
     */
    private function header(string $png): ?array
    {
        if (! str_starts_with($png, self::SIGNATURE) || strlen($png) < 33) {
            return null;
        }

        // The signature, then IHDR's own 4-byte length and 4-byte type.
        $fields = unpack('Nwidth/Nheight/Cdepth/Ccolour/Ccompression/Cfilter/Cinterlace', substr($png, 16, 13));

        if ($fields === false || $fields['depth'] !== 8 || $fields['interlace'] !== 0) {
            return null;
        }

        /** @var array{width: int, height: int, depth: int, colour: int, compression: int, filter: int, interlace: int} $fields */
        $width = $fields['width'];
        $height = $fields['height'];

        // 2 is truecolour, 6 is truecolour with alpha. Palette and greyscale
        // are legal PNG and are not what an encoder hands back here.
        $colours = match ($fields['colour']) {
            2 => 3,
            6 => 4,
            default => 0,
        };

        if ($colours === 0 || $width < 1 || $height < 1) {
            return null;
        }

        return ['width' => $width, 'height' => $height, 'colours' => $colours];
    }

    /**
     * Every IDAT chunk's payload, concatenated.
     *
     * An encoder is free to split the compressed stream across chunks and GD
     * does, so taking the first would decode a fraction of the image.
     */
    private function pixelData(string $png): string
    {
        $data = '';
        $position = strlen(self::SIGNATURE);
        $length = strlen($png);

        while ($position + 8 <= $length) {
            $header = unpack('Nsize/a4type', substr($png, $position, 8));

            if ($header === false) {
                break;
            }

            /** @var array{size: int, type: string} $header */
            $size = $header['size'];

            if ($header['type'] === 'IDAT') {
                $data .= substr($png, $position + 8, $size);
            }

            if ($header['type'] === 'IEND') {
                break;
            }

            // Chunk header, payload, and the four-byte CRC that follows it.
            $position += 12 + $size;
        }

        return $data;
    }

    /**
     * Separates interleaved RGBA into the two planes PDF wants.
     *
     * §8.9.5.4 keeps them apart: the image XObject carries the colour and the
     * /SMask carries the alpha, as its own single-component greyscale image.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $samples, int $pixels): array
    {
        $rgb = '';
        $alpha = '';

        for ($index = 0; $index < $pixels; $index++) {
            $offset = $index * 4;
            $rgb .= substr($samples, $offset, 3);
            $alpha .= $samples[$offset + 3];
        }

        return [$rgb, $alpha];
    }
}
