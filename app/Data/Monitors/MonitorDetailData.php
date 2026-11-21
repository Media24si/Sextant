<?php

namespace App\Data\Monitors;

use Spatie\LaravelData\Data;

/** The monitor drawer: effective settings, state and the last 100 runs (SPEC §13.6). */
class MonitorDetailData extends Data
{
    public function __construct(
        public MonitorRowData $monitor,
        /** @var list<MonitorSettingData> */
        public array $settings,
        public int $consecutive_failures,
        public int $consecutive_successes,
        public int $consecutive_unknowns,
        public ?string $last_definitive_at,
        public ?string $stale_at,
        /** @var list<MonitorRunData> */
        public array $runs,
        public MonitorFormData $form,
        public ?CheckerOptionData $checker,
    ) {}
}
