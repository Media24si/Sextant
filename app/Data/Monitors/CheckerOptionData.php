<?php

namespace App\Data\Monitors;

use Spatie\LaravelData\Data;

/** A checker that can be added to a resource, with its defaults and schema. */
class CheckerOptionData extends Data
{
    public function __construct(
        public string $key,
        public string $name,
        public int $interval,
        public int $failures_before_incident,
        public int $successes_before_resolve,
        /** @var list<ArgumentSchemaData> */
        public array $arguments,
    ) {}
}
