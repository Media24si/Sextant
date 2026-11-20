<?php

namespace App\Net\Results;

use Carbon\CarbonImmutable;

/**
 * A registry record from RDAP (SPEC §9.3, docs/spikes/rdap.md). `found` is
 * false for a 404. Dates are calendar dates in Europe/Ljubljana.
 */
final readonly class RdapDomain
{
    /**
     * @param  list<string>  $statuses  lowercase, e.g. "client transfer prohibited"
     * @param  list<string>  $nameservers  lowercase, no trailing dots
     */
    public function __construct(
        public bool $found,
        public string $server,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $registeredAt = null,
        public ?CarbonImmutable $lastChangedAt = null,
        public ?string $registrar = null,
        public array $statuses = [],
        public array $nameservers = [],
    ) {}
}
