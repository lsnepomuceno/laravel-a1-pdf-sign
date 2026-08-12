<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Seal;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\File;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SealRenderer;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealLayout;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Enums\ImageDriver;
use LSNepomuceno\LaravelA1PdfSign\Enums\SealEncoding;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Support\PngReader;

/**
 * Renders the seal with Intervention Image.
 *
 * Everything the v1 code hard-coded (driver, font file, size, colour and the
 * background image) comes from configuration, and the result is returned as
 * bytes rather than written to a temporary file.
 *
 * Two things the seal could not do until now, both in
 * docs/decisions/0023-a-seal-that-can-be-transparent.md: it was always an
 * opaque rectangle, because JPEG has no alpha channel and the artwork's own
 * transparency was flattened away at encode time; and it always said the same
 * three things, at three baselines fixed in the source.
 */
final readonly class InterventionSealRenderer implements SealRenderer
{
    public function __construct(
        private Config $config,
        private PngReader $png = new PngReader(),
    ) {}

    public function render(
        Certificate $certificate,
        FontSize|string|null $fontSize = null,
        bool $showExpiry = false,
        string $expiryFormat = 'd/m/Y H:i:s',
        ?SealLayout $layout = null,
    ): SealImage {
        $size = FontSize::resolve($fontSize ?? $this->configured('seal.font.size', 'large') ?? 'large');

        // An empty layout means every default, so the rest of this method reads
        // one shape rather than branching on null at every property.
        $layout ??= new SealLayout();

        $image = new ImageManager(driver: $this->driver()->create())
            ->read($layout->background ?? $this->background());

        $lines = $layout->hasLines()
            ? $layout->lines
            : $this->rows($certificate, $size, $showExpiry, $expiryFormat);

        $x = $layout->x ?? $this->intConfig('seal.text.x', 160);
        $rows = $layout->rows !== [] ? $layout->rows : $this->configuredRows();

        foreach ($lines as $index => $text) {
            // A line with no baseline is not drawn. Stacking it onto the last
            // one would produce two lines of text on top of each other, which
            // looks like a rendering fault rather than a caller mistake.
            if (isset($rows[$index])) {
                $image->text($text, $x, $rows[$index], $this->font($size, $layout));
            }
        }

        return $this->transparent($layout)
            ? $this->withAlpha($image)
            : $this->opaque($image);
    }

    public function fromImage(string $imagePath, ?SealLayout $layout = null): SealImage
    {
        if (! File::exists($imagePath)) {
            throw new FileNotFoundException($imagePath);
        }

        $layout ??= new SealLayout();

        $image = new ImageManager(driver: $this->driver()->create())->read($imagePath);

        $x = $layout->x ?? $this->intConfig('seal.text.x', 160);
        $rows = $layout->rows !== [] ? $layout->rows : $this->configuredRows();
        $size = FontSize::resolve($this->configured('seal.font.size', 'large') ?? 'large');

        // Only what the layout says. A caller who supplied their own artwork
        // did not ask for the certificate's details printed over it.
        foreach ($layout->lines as $index => $text) {
            if (isset($rows[$index])) {
                $image->text($text, $x, $rows[$index], $this->font($size, $layout));
            }
        }

        return $this->transparent($layout) ? $this->withAlpha($image) : $this->opaque($image);
    }

    /**
     * The seal as deflated RGB samples plus an alpha plane.
     *
     * Falls back to the opaque form when the encoder hands back a PNG this
     * cannot separate, since a seal that renders is better than a refusal, and
     * the only thing lost is the transparency.
     */
    private function withAlpha(ImageInterface $image): SealImage
    {
        $planes = $this->png->planes($image->encode(new PngEncoder())->toString());

        if ($planes === null || $planes['alpha'] === null) {
            return $this->opaque($image);
        }

        $rgb = gzcompress($planes['rgb']);
        $alpha = gzcompress($planes['alpha']);

        if ($rgb === false || $alpha === false) {
            return $this->opaque($image);
        }

        return new SealImage(
            contents: $rgb,
            width: $planes['width'],
            height: $planes['height'],
            mimeType: SealEncoding::Rgb->mimeType(),
            alpha: $alpha,
            encoding: SealEncoding::Rgb,
        );
    }

    private function opaque(ImageInterface $image): SealImage
    {
        return new SealImage(
            contents: $image->encode(new JpegEncoder())->toString(),
            width: $image->width(),
            height: $image->height(),
        );
    }

    private function transparent(SealLayout $layout): bool
    {
        return $layout->transparent ?? (bool) $this->config->get('a1-pdf-sign.seal.transparent', true);
    }

    /**
     * Subject line, issuer line, and optionally the expiry date.
     *
     * @return list<string>
     */
    private function rows(
        Certificate $certificate,
        FontSize $size,
        bool $showExpiry,
        string $expiryFormat,
    ): array {
        $subject = $certificate->commonName();
        $issuer = $this->issuerName($certificate);

        $expiresAt = $certificate->expiresAt();
        $expiry = $showExpiry && $expiresAt !== null
            ? date($expiryFormat, $expiresAt)
            : '';

        return [
            $this->wrap($subject ?? $issuer ?? '', $size),
            $this->wrap($subject !== null ? ($issuer ?? '') : '', $size),
            $expiry,
        ];
    }

    private function issuerName(Certificate $certificate): ?string
    {
        $issuer = $certificate->data['issuer'] ?? [];

        if (! is_array($issuer)) {
            return null;
        }

        $name = $issuer['organizationalUnitName']
            ?? $issuer['commonName']
            ?? $issuer['organizationName']
            ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Breaks a line that would overflow the seal at the width the type allows.
     */
    private function wrap(string $text, FontSize $size): string
    {
        $limit = $size->cropLength();

        if (strlen($text) < $limit) {
            return $text;
        }

        return implode(PHP_EOL, array_map('trim', str_split($text, max(1, $limit - 3))));
    }

    private function font(FontSize $size, SealLayout $layout): callable
    {
        $path = $layout->fontPath
            ?? $this->configured('seal.font.path')
            ?? dirname(__DIR__) . '/Resources/font/Roboto-Medium.ttf';

        $colour = $layout->color ?? $this->configured('seal.font.color', '#16A085');

        return static function (FontFactory $font) use ($path, $size, $colour): void {
            $font->file($path);
            $font->size($size->points());
            $font->color($colour);
        };
    }

    /**
     * Baseline of each line, in pixels from the top.
     *
     * @return list<int>
     */
    private function configuredRows(): array
    {
        $configured = $this->config->get('a1-pdf-sign.seal.text.rows');

        if (! is_array($configured) || $configured === []) {
            return [80, 150, 250];
        }

        $rows = [];

        foreach ($configured as $row) {
            if (is_numeric($row)) {
                $rows[] = (int) $row;
            }
        }

        return $rows;
    }

    private function background(): string
    {
        return $this->configured('seal.background')
            ?? dirname(__DIR__) . '/Resources/img/sign-seal.png';
    }

    private function driver(): ImageDriver
    {
        return ImageDriver::tryFrom($this->configured('seal.driver', 'gd') ?? 'gd') ?? ImageDriver::Gd;
    }

    private function configured(string $key, ?string $default = null): ?string
    {
        $value = $this->config->get("a1-pdf-sign.{$key}", $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get("a1-pdf-sign.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
