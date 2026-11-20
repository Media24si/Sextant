<?php

namespace App\Data;

use App\Models\Domain;
use Spatie\LaravelData\Data;

/**
 * The simple domain form of Phase 1. Phase 2's Add domain (SPEC §10.5) adds
 * the registry lookup and subdomains.
 */
class DomainFormData extends Data
{
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $notes,
    ) {}

    public static function fromModel(Domain $domain): self
    {
        return new self($domain->id, $domain->display_name, $domain->notes);
    }
}
