<?php

namespace App\Data\Incidents;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/** The first or last result of an incident. */
class IncidentResultData extends Data
{
    public function __construct(
        public Status $status,
        public string $message,
        /** @var array<string, mixed> */
        public array $data,
    ) {}
}
