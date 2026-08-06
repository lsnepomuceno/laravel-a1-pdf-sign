<?php

namespace LSNepomuceno\LaravelA1PdfSign\Seal;

use Illuminate\Contracts\Config\Repository as Config;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SealRenderer;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Enums\ImageDriver;

/**
 * Renders the seal with Intervention Image.
 *
 * Everything the v1 code hard-coded — driver, font file, size, colour and the
 * background image — now comes from configuration, and the result is returned
 * as bytes rather than written to a temporary file.
 */
final readonly class InterventionSealRenderer implements SealRenderer
{
    private const int TEXT_X = 160;

    /** Baseline of each of the three text rows, in pixels from the top. */
    private const array TEXT_ROWS = [80, 150, 250];

    public function __construct(private Config $config) {}

    public function render(
        Certificate $certificate,
        FontSize|string|null $fontSize = null,
        bool $showExpiry = false,
        string $expiryFormat = 'd/m/Y H:i:s',
    ): SealImage {
        $size = FontSize::resolve($fontSize ?? $this->configured('seal.font.size', 'large') ?? 'large');

        $image = (new ImageManager(driver: $this->driver()->create()))->read($this->background());

        foreach ($this->rows($certificate, $size, $showExpiry, $expiryFormat) as $index => $text) {
            $image->text($text, self::TEXT_X, self::TEXT_ROWS[$index], $this->font($size));
        }

        return new SealImage(
            contents: $image->encode(new JpegEncoder())->toString(),
            width: $image->width(),
            height: $image->height(),
        );
    }

    /**
     * Subject line, issuer line, and optionally the expiry date.
     *
     * @return array<int, string>
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

    private function font(FontSize $size): callable
    {
        $path = $this->configured('seal.font.path') ?? dirname(__DIR__) . '/Resources/font/Roboto-Medium.ttf';
        $colour = $this->configured('seal.font.color', '#16A085');

        return static function (FontFactory $font) use ($path, $size, $colour): void {
            $font->file($path);
            $font->size($size->points());
            $font->color($colour);
        };
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
}
