<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * The optional metadata a reader shows alongside a signature.
 */
final readonly class SignatureInfo extends BaseData
{
    public function __construct(
        public ?string $name = null,
        public ?string $location = null,
        public ?string $reason = null,
        public ?string $contactInfo = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->name === null
            && $this->location === null
            && $this->reason === null
            && $this->contactInfo === null;
    }

    /**
     * Keyed by the PDF dictionary names, skipping anything unset.
     *
     * @return array<string, string>
     */
    public function toDictionary(): array
    {
        return array_filter([
            'Name' => $this->name,
            'Location' => $this->location,
            'Reason' => $this->reason,
            'ContactInfo' => $this->contactInfo,
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }
}
