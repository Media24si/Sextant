<?php

namespace App\Data\Monitors;

use Spatie\LaravelData\Data;

/**
 * One checker on the Monitor defaults page: the config values (shown as
 * placeholders) and the defaults stored on top of them.
 */
class CheckerDefaultsData extends Data
{
    public function __construct(
        public string $key,
        public string $name,
        /** server, domain, site or repository */
        public string $resource_type,
        public int $interval,
        public int $failures_before_incident,
        public int $successes_before_resolve,
        /** @var list<ArgumentSchemaData> defaults are the config values */
        public array $arguments,
        /** @var array<string, mixed> stored argument defaults only */
        public array $overrides,
        public ?int $interval_minutes,
        public ?int $failures_before_incident_override,
        public ?int $successes_before_resolve_override,
    ) {}
}
