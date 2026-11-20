<?php

namespace App\Data\Detection;

use App\Enums\Confidence;
use Spatie\LaravelData\Data;

/** A detection finding (SPEC §10.1 `Finding<T>`). A null value with `detected` means DNS points straight at our server. */
class FrontFindingData extends Data
{
    public function __construct(
        public ?DetectedServiceData $value,
        public Confidence $confidence,
        public string $explanation,
    ) {}
}
