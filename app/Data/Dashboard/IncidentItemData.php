<?php

namespace App\Data\Dashboard;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/** An open incident on the dashboard (SPEC §13.7). */
class IncidentItemData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public string $message,
        /** warning or error */
        public Status $severity,
        public string $opened_at,
        public ?string $acknowledged_by,
        public bool $recovering,
        /** e.g. "Varnish → web03", for site incidents */
        public ?string $serving_path,
        public string $url,
    ) {}
}
