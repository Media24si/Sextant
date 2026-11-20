<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** A monitor detection would attach, with only the arguments it overrides (SPEC §4.4, §10.1 step 8). */
class ProposedMonitorData extends Data
{
    public function __construct(
        /** Registry key, e.g. `site.http`. */
        public string $checker,
        public ?string $label,
        /** @var array<string, mixed> */
        public array $arguments,
    ) {}
}
