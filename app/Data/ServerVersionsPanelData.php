<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * The Software versions panel of a server page (SPEC §13.6). Carries the
 * masked token only.
 */
class ServerVersionsPanelData extends Data
{
    public function __construct(
        public bool $configured,
        public ?string $endpoint_url,
        public ?string $token_masked,
        public ?string $read_at,
        public ?string $fetched_at,
        public ?string $os_reported,
        public ?string $os_set,
        /** The versions monitor's latest message, e.g. "Collector last ran 3 d ago" */
        public ?string $last_message,
        /** @var list<ServerVersionData> */
        public array $versions,
    ) {}
}
