<?php

namespace App\Data;

use App\Models\MinimumVersion;
use Spatie\LaravelData\Data;

/** A row of the Minimum versions page (SPEC §13.6). */
class MinimumVersionRowData extends Data
{
    public function __construct(
        public int $id,
        /** kernel, nginx, php, mysql or openssl */
        public string $software,
        /** e.g. "PHP" */
        public string $label,
        /** @var list<string> one per branch, e.g. ["8.2.36", "8.3.37"] */
        public array $min_versions,
        public ?string $cve_url,
        public ?string $note,
        /** @var list<array{id: int, name: string, version: string}> */
        public array $servers_below,
        public string $set_by,
        public string $updated_at,
    ) {}

    /**
     * @param  list<array{id: int, name: string, version: string}>  $serversBelow
     */
    public static function fromModel(MinimumVersion $minimum, array $serversBelow): self
    {
        return new self(
            id: $minimum->id,
            software: $minimum->software->value,
            label: $minimum->label(),
            min_versions: $minimum->min_versions,
            cve_url: $minimum->cve_url,
            note: $minimum->note,
            servers_below: $serversBelow,
            set_by: $minimum->setBy->name,
            updated_at: ($minimum->updated_at ?? $minimum->created_at ?? now())->toIso8601String(),
        );
    }
}
