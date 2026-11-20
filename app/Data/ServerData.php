<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Choice;
use App\Models\Server;
use Spatie\LaravelData\Data;

/**
 * The header of a server page (SPEC §13.6). Expects `addresses`, `os` and
 * `backupLocations` loaded. Never carries the versions token.
 */
class ServerData extends Data
{
    /**
     * @param  list<ServerAddressData>  $addresses
     * @param  list<string>  $backup_locations
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $hostname,
        public ?string $provider,
        public ?string $os,
        public ?string $notes,
        public Status $status,
        public array $addresses,
        public array $backup_locations,
        public bool $deleted,
    ) {}

    public static function fromModel(Server $server): self
    {
        return new self(
            id: $server->id,
            name: $server->name,
            hostname: $server->hostname,
            provider: $server->provider,
            os: $server->os?->label,
            notes: $server->notes,
            status: $server->status,
            addresses: array_values($server->addresses->map(ServerAddressData::fromModel(...))->all()),
            backup_locations: array_values($server->backupLocations->map(fn (Choice $choice): string => $choice->label)->all()),
            deleted: $server->trashed(),
        );
    }
}
