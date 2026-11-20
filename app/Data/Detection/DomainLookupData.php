<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** Add domain's registry lookup (SPEC §10.5). */
class DomainLookupData extends Data
{
    public function __construct(
        public string $name,
        public string $display_name,
        /** Set when the domain is already in Sextant. */
        public ?int $existing_id,
        public ?RegistryPreviewData $registry,
    ) {}
}
