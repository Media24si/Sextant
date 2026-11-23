<?php

namespace App\Data;

use App\Enums\ExternalServiceKind;
use App\Enums\RangeSource;
use App\Models\ExternalService;
use Spatie\LaravelData\Data;

/**
 * The header of an external service's page.
 */
class ExternalServiceData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ExternalServiceKind $kind,
        /** @var list<string> */
        public array $ranges,
        public ?RangeSource $ranges_source,
        /** @var list<string> */
        public array $cname_suffixes,
        /** @var list<string> */
        public array $response_headers,
        public ?string $notes,
        public bool $deleted,
    ) {}

    public static function fromModel(ExternalService $service): self
    {
        return new self(
            id: $service->id,
            name: $service->name,
            kind: $service->kind,
            ranges: $service->ranges,
            ranges_source: $service->ranges_source,
            cname_suffixes: $service->cname_suffixes ?? [],
            response_headers: $service->response_headers ?? [],
            notes: $service->notes,
            deleted: $service->trashed(),
        );
    }
}
