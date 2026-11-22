<?php

namespace App\Data;

use App\Models\ServerSoftware;
use App\Versions\MinimumVersions;
use Spatie\LaravelData\Data;

/** One software version on a server, against its minimum (SPEC §11.6, §13.6). */
class ServerVersionData extends Data
{
    public function __construct(
        /** os, kernel, nginx, php, mysql or openssl */
        public string $software,
        public string $branch,
        /** e.g. "PHP 8.2" */
        public string $label,
        public string $version,
        public ?string $package_version,
        public ?string $min_version,
        public bool $below_minimum,
        public string $read_at,
        /** @var array<string, mixed>|null e.g. {"cli": true, "fpm": true} or the OS name */
        public ?array $details,
    ) {}

    public static function fromModel(ServerSoftware $row): self
    {
        $minimums = app(MinimumVersions::class);

        return new self(
            software: $row->software->value,
            branch: $row->branch,
            label: $row->label(),
            version: $row->version,
            package_version: $row->package_version,
            min_version: $minimums->floorFor($row),
            below_minimum: $minimums->isBelow($row),
            read_at: $row->read_at->toIso8601String(),
            details: $row->details,
        );
    }
}
