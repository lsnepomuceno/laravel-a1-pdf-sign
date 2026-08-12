<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealLayout;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;

/**
 * Draws the visual seal that represents a signature.
 */
interface SealRenderer
{
    /**
     * @param  bool  $showExpiry  Whether to print the certificate's expiry date.
     * @param  ?SealLayout  $layout  What the seal says and where, overriding the
     *                               certificate-derived lines and the configured
     *                               geometry. Null keeps both
     *                               (docs/decisions/0023-a-seal-that-can-be-transparent.md).
     */
    public function render(
        Certificate $certificate,
        FontSize|string|null $fontSize = null,
        bool $showExpiry = false,
        string $expiryFormat = 'd/m/Y H:i:s',
        ?SealLayout $layout = null,
    ): SealImage;

    /**
     * A seal from an image the caller already has, skipping the certificate.
     *
     * The image is embedded as it is drawn, so a PNG with transparency stays
     * transparent. A layout may still add text over it; without one, nothing is
     * drawn and the caller's artwork is the whole seal.
     *
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException
     */
    public function fromImage(string $imagePath, ?SealLayout $layout = null): SealImage;
}
