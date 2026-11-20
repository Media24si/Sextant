<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/**
 * What the registry says about a new domain (SPEC §10.1 step 3, §10.5).
 * Dates are `Y-m-d` calendar days in Europe/Ljubljana.
 */
class RegistryPreviewData extends Data
{
    public function __construct(
        /** rdap or whois */
        public string $source,
        public ?string $server,
        public bool $found,
        public ?string $registrar,
        public ?string $expires_at,
        /** @var list<string> */
        public array $nameservers,
        /** @var list<string> */
        public array $statuses,
        /** Set when the registry couldn't be asked. */
        public ?string $error = null,
    ) {}
}
