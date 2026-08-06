<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;

/**
 * Draws the visual seal that represents a signature.
 */
interface SealRenderer
{
    /**
     * @param  bool  $showExpiry  Whether to print the certificate's expiry date.
     */
    public function render(
        Certificate $certificate,
        FontSize|string|null $fontSize = null,
        bool $showExpiry = false,
        string $expiryFormat = 'd/m/Y H:i:s',
    ): SealImage;
}
