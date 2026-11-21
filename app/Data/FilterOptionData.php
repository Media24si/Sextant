<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/** A string value and its label, for a filter select (e.g. checkers). */
class FilterOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
