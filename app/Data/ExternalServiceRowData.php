<?php

namespace App\Data;

use App\Enums\ExternalServiceKind;
use App\Enums\RangeSource;
use App\Models\ExternalService;
use Spatie\LaravelData\Data;

/**
 * A row of the External services list (SPEC §13.6). Expects `sites_count`.
 */
class ExternalServiceRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ExternalServiceKind $kind,
        public int $ranges_count,
        public ?RangeSource $ranges_source,
        public int $sites_count,
        public bool $deleted,
    ) {}

    public static function fromModel(ExternalService $service): self
    {
        return new self(
            id: $service->id,
            name: $service->name,
            kind: $service->kind,
            ranges_count: count($service->ranges),
            ranges_source: $service->ranges_source,
            sites_count: (int) $service->getAttribute('sites_count'),
            deleted: $service->trashed(),
        );
    }
}
