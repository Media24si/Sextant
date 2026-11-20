<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/** An id and a label for a select. */
class OptionData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
    ) {}
}
