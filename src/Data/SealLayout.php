<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * What the seal says and where on the artwork it says it.
 *
 * The renderer drew three lines, at three baselines hard-coded in pixels, from
 * fields it chose out of the certificate. That is right for the common case and
 * impossible to leave: a seal that has to carry a protocol number, a department
 * or a second language had nowhere to put it.
 *
 * Every property is optional and null means "use the configured default", which
 * is the same rule the rest of the package follows: an infrastructure decision
 * belongs in configuration, not repeated at every call site.
 *
 * See docs/decisions/0023-a-seal-that-can-be-transparent.md.
 */
final readonly class SealLayout extends BaseData
{
    /**
     * @param  list<string>  $lines  The text to draw. Empty keeps the lines the
     *                               renderer derives from the certificate, so a
     *                               layout can move the default text without
     *                               restating it.
     * @param  list<int>  $rows  Baseline of each line, in pixels from the top.
     *                           Empty uses the configured rows. A line with no
     *                           row is not drawn, rather than stacked onto the
     *                           last one.
     * @param  ?int  $x  Left edge of the text, in pixels.
     * @param  ?bool  $transparent  Whether to honour the artwork's alpha
     *                              channel. Null uses the configured default,
     *                              which is to honour it.
     */
    public function __construct(
        public array $lines = [],
        public array $rows = [],
        public ?int $x = null,
        public ?string $fontPath = null,
        public ?string $color = null,
        public ?string $background = null,
        public ?bool $transparent = null,
    ) {}

    /**
     * A layout that only replaces the text.
     *
     * @param  list<string>  $lines
     */
    public static function saying(array $lines): self
    {
        return new self(array_values($lines));
    }

    public function hasLines(): bool
    {
        return $this->lines !== [];
    }
}
