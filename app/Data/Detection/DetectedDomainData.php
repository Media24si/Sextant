<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** The registrable domain a hostname belongs to. */
class DetectedDomainData extends Data
{
    public function __construct(
        public string $name,
        public string $display_name,
        public ?int $existing_id,
        public int $site_count,
        public ?RegistryPreviewData $registry = null,
    ) {}
}
