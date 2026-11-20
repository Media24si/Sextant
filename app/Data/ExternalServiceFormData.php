<?php

namespace App\Data;

use App\Enums\ExternalServiceKind;
use App\Enums\RangeSource;
use App\Models\ExternalService;
use Spatie\LaravelData\Data;

/** The values of the external service form (SPEC §3.5). */
class ExternalServiceFormData extends Data
{
    /**
     * @param  list<string>  $ranges
     * @param  list<string>  $cname_suffixes
     * @param  list<string>  $response_headers
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ExternalServiceKind $kind,
        public array $ranges,
        public ?RangeSource $ranges_source,
        public array $cname_suffixes,
        public array $response_headers,
        public ?string $notes,
    ) {}

    public static function blank(): self
    {
        return new self(null, '', ExternalServiceKind::Proxy, [], null, [], [], null);
    }

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
        );
    }
}
