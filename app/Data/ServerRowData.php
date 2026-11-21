<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Choice;
use App\Models\Server;
use Spatie\LaravelData\Data;

/**
 * A row of the Servers list (SPEC §13.6). Expects `primaryAddress`, `os` and
 * `backupLocations` loaded and `sites_count` counted.
 */
class ServerRowData extends Data
{
    /**
     * @param  list<string>  $backup_locations
     */
    public function __construct(
        public int $id,
        public string $name,
        public Status $status,
        public ?string $primary_ip,
        public ?string $os,
        public int $sites_count,
        public array $backup_locations,
        public bool $deleted,
        public bool $versions_configured = false,
        /** @var list<ServerVersionData> */
        public array $versions = [],
    ) {}

    public static function fromModel(Server $server): self
    {
        return new self(
            id: $server->id,
            name: $server->name,
            status: $server->status,
            primary_ip: $server->primaryAddress?->ip,
            os: $server->os?->label,
            sites_count: (int) $server->getAttribute('sites_count'),
            backup_locations: array_values($server->backupLocations->map(fn (Choice $choice): string => $choice->label)->all()),
            deleted: $server->trashed(),
            versions_configured: $server->versions_url !== null,
            versions: array_values($server->software->map(ServerVersionData::fromModel(...))->all()),
        );
    }
}
