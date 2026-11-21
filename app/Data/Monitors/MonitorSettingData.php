<?php

namespace App\Data\Monitors;

use Spatie\LaravelData\Data;

/** An effective setting in the drawer: the value and whether it comes from the registry (§4.5). */
class MonitorSettingData extends Data
{
    public function __construct(
        public string $label,
        public string $value,
        public bool $inherited,
    ) {}
}
