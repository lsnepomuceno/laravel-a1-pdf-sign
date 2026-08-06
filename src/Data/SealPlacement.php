<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * Where the visual seal goes.
 *
 * Coordinates are in PDF user space, measured from the bottom-left corner.
 */
final readonly class SealPlacement extends BaseData
{
    public const int LAST_PAGE = -1;

    public function __construct(
        public string $imagePath,
        public float $x = 155,
        public float $y = 250,
        public float $width = 50,
        public float $height = 0,
        public int $page = self::LAST_PAGE,
        public bool $onEveryPage = false,
    ) {}

    /**
     * Whether the seal belongs on $pageNumber of a $pageCount document.
     */
    public function appliesTo(int $pageNumber, int $pageCount): bool
    {
        if ($this->onEveryPage) {
            return true;
        }

        return $this->page === self::LAST_PAGE
            ? $pageNumber === $pageCount
            : $pageNumber === $this->page;
    }
}
