<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * The outcome of inspecting a PDF's signature.
 *
 * `isValidated` reports whether identifying fields were found in the embedded
 * certificate, not whether the signature verifies cryptographically. See
 * ARCHITECTURE-V2.md §3b — PR 9 replaces the underlying text parsing.
 */
readonly class SignatureReport extends BaseData
{
    /**
     * @param  array<string, array<int, string>>  $data
     */
    public function __construct(
        public bool $isValidated,
        public array $data,
    ) {}
}
