<?php

namespace App\Data\Import;

use App\Enums\ImportOutcome;
use Spatie\LaravelData\Data;

/** One imported URL (SPEC §10.6). */
class ImportResultData extends Data
{
    public function __construct(
        public string $input,
        public ?string $hostname,
        public ImportOutcome $outcome,
        /** e.g. "Varnish → web02" or "Backend not assigned (suggested: web02)". */
        public string $message,
        public ?int $site_id,
    ) {}
}
