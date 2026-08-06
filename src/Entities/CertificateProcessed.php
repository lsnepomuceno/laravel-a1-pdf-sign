<?php

namespace LSNepomuceno\LaravelA1PdfSign\Entities;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;

/**
 * @deprecated 2.0 Use {@see Certificate} instead. Removed in 3.0.
 *
 * Kept as a subclass so existing `instanceof CertificateProcessed` checks keep
 * passing while new code can type-hint the parent.
 */
readonly class CertificateProcessed extends Certificate {}
