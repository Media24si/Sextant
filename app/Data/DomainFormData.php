<?php

namespace App\Data;

use App\Models\Domain;
use Spatie\LaravelData\Data;

/**
 * Edit domain. Adding one is Add domain (SPEC §10.5). `sites` counts live
 * sites: a domain with sites keeps its name (DomainRequest).
 */
class DomainFormData extends Data
{
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $notes,
        public int $sites = 0,
    ) {}

    public static function fromModel(Domain $domain): self
    {
        return new self($domain->id, $domain->display_name, $domain->notes, $domain->sites()->count());
    }
}
