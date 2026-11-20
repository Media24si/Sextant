<?php

namespace App\Data\Detection;

use App\Enums\Confidence;
use Spatie\LaravelData\Data;

/** A detection finding (SPEC §10.1 `Finding<T>`). */
class DomainFindingData extends Data
{
    public function __construct(
        public DetectedDomainData $value,
        public Confidence $confidence,
        public string $explanation,
    ) {}
}
