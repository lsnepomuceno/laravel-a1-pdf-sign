<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

/**
 * An sRGB ICC profile, built from published numbers rather than vendored.
 *
 * A seal is embedded as /DeviceRGB, which PDF/A allows only where the file
 * carries an RGB OutputIntent. Adding an OutputIntent is the author's statement
 * about their own document rather than the signer's, so a visible seal cost
 * conformance (docs/decisions/0025-what-signing-does-to-pdf-a.md). An /ICCBased
 * colour space carries its own profile and needs no such declaration.
 *
 * It needs a profile to carry, and embedding a third party's binary raises a
 * licensing question in an MIT package. So this builds one: the primaries,
 * white point and transfer function come from IEC 61966-2-1, the file format
 * from ICC.1:2001-04, and both are public specifications. Same clean-room
 * reasoning invariant 1 applies to ISO 32000-1.
 *
 * The output is deterministic, and the colorants it computes agree with the
 * published sRGB profile's to four decimal places, with the Bradford matrix
 * matching exactly. Nothing asserts that yet: this arrived as a spike, and a
 * test of the arithmetic belongs with the decision to adopt it.
 *
 * @internal
 */
final readonly class SrgbProfile
{
    /**
     * IEC 61966-2-1 §2: the sRGB primaries, as CIE xy chromaticities.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const array PRIMARIES = [
        'red' => [0.6400, 0.3300],
        'green' => [0.3000, 0.6000],
        'blue' => [0.1500, 0.0600],
    ];

    /** @var array{0: float, 1: float} */
    private const array WHITE_POINT = [0.3127, 0.3290];

    /**
     * ICC.1:2001-04 §6.1.4: the profile connection space is illuminated by D50,
     * and these are the values the header is required to carry. They are
     * therefore also what the primaries are adapted to.
     *
     * @var array{0: float, 1: float, 2: float}
     */
    private const array CONNECTION_WHITE = [0.9642, 1.0, 0.8249];

    /**
     * ICC v2 has no parametric curve type, so the transfer function ships as a
     * table. The reference profiles sample it at 1024 points.
     */
    private const int CURVE_POINTS = 1024;

    /**
     * The profile, as the bytes that go into the stream.
     */
    public function bytes(): string
    {
        $adaptation = $this->chromaticAdaptation();
        $colorants = $this->multiply($adaptation, $this->rgbToXyz());
        $curve = $this->toneCurve();

        // The three curves are the same 2 KB block and ICC.1 §7.3 lets tags
        // share data, so they are written once and pointed at three times.
        return $this->assemble([
            'desc' => $this->description('sRGB IEC61966-2.1'),
            'cprt' => $this->text('Generated from IEC 61966-2-1. No rights reserved.'),
            'wtpt' => $this->xyz(self::CONNECTION_WHITE),
            'chad' => $this->matrix($adaptation),
            'rXYZ' => $this->xyz([$colorants[0][0], $colorants[1][0], $colorants[2][0]]),
            'gXYZ' => $this->xyz([$colorants[0][1], $colorants[1][1], $colorants[2][1]]),
            'bXYZ' => $this->xyz([$colorants[0][2], $colorants[1][2], $colorants[2][2]]),
            'rTRC' => $curve,
            'gTRC' => $curve,
            'bTRC' => $curve,
        ]);
    }

    /**
     * The header, tag table and tag data, in that order.
     *
     * @param  array<string, string>  $tags
     */
    private function assemble(array $tags): string
    {
        $offset = 128 + 4 + 12 * count($tags);

        $table = pack('N', count($tags));
        $data = '';

        /** @var array<string, array{0: int, 1: int}> $placed */
        $placed = [];

        foreach ($tags as $signature => $element) {
            // Keyed by the bytes themselves rather than by a digest of them:
            // the point is only to place identical elements once, and a hash
            // would be a weaker test of equality for no gain.
            if (! isset($placed[$element])) {
                $placed[$element] = [$offset + strlen($data), strlen($element)];

                // ICC.1 §7.3.1: every tag begins on a four-byte boundary.
                $data .= $element . str_repeat("\0", (4 - strlen($element) % 4) % 4);
            }

            $table .= $signature . pack('N', $placed[$element][0]) . pack('N', $placed[$element][1]);
        }

        return $this->header(128 + strlen($table) + strlen($data)) . $table . $data;
    }

    /**
     * ICC.1:2001-04 §6.1, the 128-byte header.
     */
    private function header(int $size): string
    {
        return pack('N', $size)
            . str_repeat("\0", 4)                       // Preferred CMM: none
            . pack('N', 0x02100000)                     // Version 2.1
            . 'mntr'                                    // Display device profile
            . 'RGB '
            . 'XYZ '
            // Fixed rather than the current time: the profile is derived from
            // constants, so a build that produced different bytes each time
            // would only be noise in a diff of two signed documents.
            . pack('n6', 2026, 1, 1, 0, 0, 0)
            . 'acsp'
            . str_repeat("\0", 4)                       // Platform
            . pack('N', 0)                              // Flags
            . str_repeat("\0", 4)                       // Device manufacturer
            . str_repeat("\0", 4)                       // Device model
            . str_repeat("\0", 8)                       // Device attributes
            . pack('N', 0)                              // Rendering intent: perceptual
            . $this->fixed(self::CONNECTION_WHITE[0])
            . $this->fixed(self::CONNECTION_WHITE[1])
            . $this->fixed(self::CONNECTION_WHITE[2])
            . str_repeat("\0", 4)                       // Creator
            . str_repeat("\0", 16)                      // Profile identifier
            . str_repeat("\0", 28);                     // Reserved
    }

    /**
     * The matrix taking sRGB to XYZ under sRGB's own white point.
     *
     * Standard colorimetry: each primary gives the direction of a column, and
     * the scaling that puts R=G=B=1 exactly on the white point gives its length.
     *
     * @return array<int, array<int, float>>
     */
    private function rgbToXyz(): array
    {
        $columns = array_map(
            fn(array $primary): array => $this->tristimulus($primary[0], $primary[1]),
            array_values(self::PRIMARIES),
        );

        $matrix = [];

        for ($row = 0; $row < 3; $row++) {
            $matrix[$row] = [$columns[0][$row], $columns[1][$row], $columns[2][$row]];
        }

        $scale = $this->apply(
            $this->invert($matrix),
            $this->tristimulus(self::WHITE_POINT[0], self::WHITE_POINT[1]),
        );

        for ($row = 0; $row < 3; $row++) {
            for ($column = 0; $column < 3; $column++) {
                $matrix[$row][$column] *= $scale[$column];
            }
        }

        return $matrix;
    }

    /**
     * The Bradford adaptation from sRGB's white point to the connection space's,
     * which ICC.1 Annex E describes and every RGB working space profile carries.
     *
     * @return array<int, array<int, float>>
     */
    private function chromaticAdaptation(): array
    {
        $cone = [
            [0.8951, 0.2664, -0.1614],
            [-0.7502, 1.7135, 0.0367],
            [0.0389, -0.0685, 1.0296],
        ];

        $from = $this->apply($cone, $this->tristimulus(self::WHITE_POINT[0], self::WHITE_POINT[1]));
        $to = $this->apply($cone, self::CONNECTION_WHITE);

        $ratio = [
            [$to[0] / $from[0], 0.0, 0.0],
            [0.0, $to[1] / $from[1], 0.0],
            [0.0, 0.0, $to[2] / $from[2]],
        ];

        return $this->multiply($this->invert($cone), $this->multiply($ratio, $cone));
    }

    /**
     * IEC 61966-2-1 §5.2, inverted: a TRC tag runs from the encoded value back
     * to linear light.
     */
    private function toneCurve(): string
    {
        $curve = 'curv' . pack('N', 0) . pack('N', self::CURVE_POINTS);

        for ($point = 0; $point < self::CURVE_POINTS; $point++) {
            $encoded = $point / (self::CURVE_POINTS - 1);

            $linear = $encoded <= 0.04045
                ? $encoded / 12.92
                : (($encoded + 0.055) / 1.055) ** 2.4;

            $curve .= pack('n', (int) round($linear * 65535.0));
        }

        return $curve;
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $values
     */
    private function xyz(array $values): string
    {
        return 'XYZ ' . pack('N', 0) . $this->fixed($values[0]) . $this->fixed($values[1]) . $this->fixed($values[2]);
    }

    /**
     * @param  array<int, array<int, float>>  $matrix
     */
    private function matrix(array $matrix): string
    {
        $element = 'sf32' . pack('N', 0);

        foreach ($matrix as $row) {
            foreach ($row as $value) {
                $element .= $this->fixed($value);
            }
        }

        return $element;
    }

    /**
     * ICC v2's textDescriptionType, which carries the same string three times
     * over. Only the ASCII one is filled in; the other two are legally empty.
     */
    private function description(string $text): string
    {
        $ascii = $text . "\0";

        return 'desc'
            . pack('N', 0)
            . pack('N', strlen($ascii))
            . $ascii
            . pack('N', 0)              // Unicode language code
            . pack('N', 0)              // Unicode count
            . pack('n', 0)              // ScriptCode code
            . chr(0)                    // Macintosh description length
            . str_repeat("\0", 67);     // Macintosh description
    }

    private function text(string $text): string
    {
        return 'text' . pack('N', 0) . $text . "\0";
    }

    /**
     * ICC.1 §5.3.6, the s15Fixed16Number.
     */
    private function fixed(float $value): string
    {
        return pack('N', (int) round($value * 65536.0) & 0xFFFFFFFF);
    }

    /**
     * The XYZ of a chromaticity at unit luminance.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function tristimulus(float $x, float $y): array
    {
        return [$x / $y, 1.0, (1.0 - $x - $y) / $y];
    }

    /**
     * @param  array<int, array<int, float>>  $matrix
     * @return array<int, array<int, float>>
     */
    private function invert(array $matrix): array
    {
        $determinant
            = $matrix[0][0] * ($matrix[1][1] * $matrix[2][2] - $matrix[1][2] * $matrix[2][1])
            - $matrix[0][1] * ($matrix[1][0] * $matrix[2][2] - $matrix[1][2] * $matrix[2][0])
            + $matrix[0][2] * ($matrix[1][0] * $matrix[2][1] - $matrix[1][1] * $matrix[2][0]);

        $inverse = [
            [
                $matrix[1][1] * $matrix[2][2] - $matrix[1][2] * $matrix[2][1],
                $matrix[0][2] * $matrix[2][1] - $matrix[0][1] * $matrix[2][2],
                $matrix[0][1] * $matrix[1][2] - $matrix[0][2] * $matrix[1][1],
            ],
            [
                $matrix[1][2] * $matrix[2][0] - $matrix[1][0] * $matrix[2][2],
                $matrix[0][0] * $matrix[2][2] - $matrix[0][2] * $matrix[2][0],
                $matrix[0][2] * $matrix[1][0] - $matrix[0][0] * $matrix[1][2],
            ],
            [
                $matrix[1][0] * $matrix[2][1] - $matrix[1][1] * $matrix[2][0],
                $matrix[0][1] * $matrix[2][0] - $matrix[0][0] * $matrix[2][1],
                $matrix[0][0] * $matrix[1][1] - $matrix[0][1] * $matrix[1][0],
            ],
        ];

        foreach ($inverse as $row => $values) {
            foreach ($values as $column => $value) {
                $inverse[$row][$column] = $value / $determinant;
            }
        }

        return $inverse;
    }

    /**
     * @param  array<int, array<int, float>>  $left
     * @param  array<int, array<int, float>>  $right
     * @return array<int, array<int, float>>
     */
    private function multiply(array $left, array $right): array
    {
        $product = [];

        for ($row = 0; $row < 3; $row++) {
            for ($column = 0; $column < 3; $column++) {
                $product[$row][$column]
                    = $left[$row][0] * $right[0][$column]
                    + $left[$row][1] * $right[1][$column]
                    + $left[$row][2] * $right[2][$column];
            }
        }

        return $product;
    }

    /**
     * @param  array<int, array<int, float>>  $matrix
     * @param  array{0: float, 1: float, 2: float}  $vector
     * @return array{0: float, 1: float, 2: float}
     */
    private function apply(array $matrix, array $vector): array
    {
        return [
            $matrix[0][0] * $vector[0] + $matrix[0][1] * $vector[1] + $matrix[0][2] * $vector[2],
            $matrix[1][0] * $vector[0] + $matrix[1][1] * $vector[1] + $matrix[1][2] * $vector[2],
            $matrix[2][0] * $vector[0] + $matrix[2][1] * $vector[1] + $matrix[2][2] * $vector[2],
        ];
    }
}
