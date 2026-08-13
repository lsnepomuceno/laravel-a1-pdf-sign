<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Support\SrgbProfile;

/**
 * The profile is built from published numbers rather than copied, so the thing
 * to check is that the arithmetic lands where the published profile lands.
 *
 * veraPDF answers whether a reader accepts it (tests/Conformance/PdfAValidationTest.php).
 * It cannot answer whether the colours are right: a structurally valid profile
 * describing the wrong primaries would pass conformance and render a seal in
 * the wrong colour, which is the failure this file exists to catch
 * (docs/decisions/0028-the-seal-carries-its-own-colour-space.md).
 */

/**
 * The tag table, as signature to raw element.
 *
 * @return array<string, array{0: int, 1: string}> Offset and bytes, by tag.
 */
function iccTags(string $profile): array
{
    /** @var array{1: int} $count */
    $count = unpack('N', substr($profile, 128, 4));

    $tags = [];

    for ($index = 0; $index < $count[1]; $index++) {
        $entry = substr($profile, 132 + $index * 12, 12);

        /** @var array{1: int, 2: int} $bounds */
        $bounds = unpack('N2', substr($entry, 4, 8));

        $tags[substr($entry, 0, 4)] = [$bounds[1], substr($profile, $bounds[1], $bounds[2])];
    }

    return $tags;
}

/**
 * The numbers in an s15Fixed16 element, past its eight-byte header.
 *
 * @return list<float>
 */
function iccNumbers(string $element): array
{
    $count = intdiv(strlen($element) - 8, 4);

    /** @var list<int> $raw */
    $raw = array_values((array) unpack("N{$count}", substr($element, 8, $count * 4)));

    return array_map(
        static fn(int $value): float => ($value > 0x7FFFFFFF ? $value - 0x100000000 : $value) / 65536.0,
        $raw,
    );
}

it('declares a header a reader can walk', function () {
    $profile = new SrgbProfile()->bytes();

    /** @var array{1: int} $size */
    $size = unpack('N', substr($profile, 0, 4));

    expect($size[1])->toBe(strlen($profile))
        // ICC.1:2001-04 §6.1.2: the signature that says this is a profile at all.
        ->and(substr($profile, 36, 4))->toBe('acsp')
        ->and(substr($profile, 12, 12))->toBe('mntrRGB XYZ ')
        // PDF/A-1 is built on PDF 1.4, which carries ICC v2. Declaring v4 here
        // would fail conformance on the version alone.
        ->and(substr($profile, 8, 4))->toBe("\x02\x10\x00\x00");
});

it('carries the tags a matrix profile needs, and no more', function () {
    $tags = iccTags(new SrgbProfile()->bytes());

    expect(array_keys($tags))->toEqualCanonicalizing([
        'desc', 'cprt', 'wtpt', 'chad', 'rXYZ', 'gXYZ', 'bXYZ', 'rTRC', 'gTRC', 'bTRC',
    ]);
});

it('writes the tone curve once and points three tags at it', function () {
    // ICC.1 §7.3 allows shared tag data, and the curve is 2 KB of the profile's
    // 2.6 KB. Writing it per channel would triple the profile for nothing.
    $tags = iccTags(new SrgbProfile()->bytes());

    expect($tags['gTRC'][0])->toBe($tags['rTRC'][0])
        ->and($tags['bTRC'][0])->toBe($tags['rTRC'][0]);
});

it('computes the colorants the published sRGB profile carries', function () {
    // The check that matters. These are the D50-adapted primaries every sRGB
    // profile in circulation holds, and they are what this class has to arrive
    // at from the chromaticities in IEC 61966-2-1 alone.
    $tags = iccTags(new SrgbProfile()->bytes());

    $expected = [
        'rXYZ' => [0.4360, 0.2225, 0.0139],
        'gXYZ' => [0.3851, 0.7169, 0.0971],
        'bXYZ' => [0.1431, 0.0606, 0.7141],
        'wtpt' => [0.9642, 1.0000, 0.8249],
    ];

    foreach ($expected as $tag => $values) {
        foreach (iccNumbers($tags[$tag][1]) as $index => $number) {
            // Half a thousandth: the Bradford coefficients are published to four
            // decimal places, so the last one is theirs rather than ours.
            expect($number)->toBeGreaterThan($values[$index] - 0.0005)
                ->and($number)->toBeLessThan($values[$index] + 0.0005);
        }
    }
});

it('adapts white with the Bradford matrix, exactly', function () {
    // Unlike the colorants this one is a straight matrix product of published
    // constants, so it matches the reference to every digit it prints.
    $tags = iccTags(new SrgbProfile()->bytes());

    expect(array_map(
        static fn(float $value): float => round($value, 4),
        iccNumbers($tags['chad'][1]),
    ))->toBe([1.0479, 0.0229, -0.0502, 0.0296, 0.9905, -0.0171, -0.0092, 0.0151, 0.7517]);
});

it('samples the transfer function from black to white, without turning back', function () {
    $tags = iccTags(new SrgbProfile()->bytes());

    /** @var array{1: int} $points */
    $points = unpack('N', substr($tags['rTRC'][1], 8, 4));

    /** @var list<int> $samples */
    $samples = array_values((array) unpack("n{$points[1]}", substr($tags['rTRC'][1], 12)));

    expect($points[1])->toBe(1024)
        ->and($samples[0])->toBe(0)
        ->and($samples[1023])->toBe(65535);

    // A transfer function that decreased anywhere would be an arithmetic slip
    // no structural check would notice.
    foreach (array_slice($samples, 1) as $index => $sample) {
        expect($sample)->toBeGreaterThan($samples[$index]);
    }
});

it('produces the same bytes every time', function () {
    // The profile is derived from constants, so two signed documents differing
    // in their colour profile would be noise in a diff. It is also what lets
    // the seal's bytes be compared at all.
    expect(new SrgbProfile()->bytes())->toBe(new SrgbProfile()->bytes());
});
