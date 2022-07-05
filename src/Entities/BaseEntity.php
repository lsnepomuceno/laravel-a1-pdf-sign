<?php

namespace LSNepomuceno\LaravelA1PdfSign\Entities;

use Illuminate\Contracts\Support\Arrayable;

class BaseEntity implements Arrayable
{
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
