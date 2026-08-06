<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Base for the package's value objects.
 *
 * @implements Arrayable<string, mixed>
 */
abstract readonly class BaseData implements Arrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> */
        return get_object_vars($this);
    }
}
