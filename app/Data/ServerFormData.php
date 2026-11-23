<?php

namespace App\Data;

use App\Http\Controllers\ServerVersionsController;
use App\Models\Choice;
use App\Models\Server;
use App\Models\ServerAddress;
use Spatie\LaravelData\Data;

/**
 * The values of the Add / edit server form (SPEC §13.6).
 */
class ServerFormData extends Data
{
    /**
     * @param  list<array{ip: string, is_primary: bool}>  $addresses
     * @param  list<int>  $backup_location_ids
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $hostname,
        public ?string $provider,
        public ?int $os_choice_id,
        public ?string $notes,
        public array $addresses,
        public array $backup_location_ids,
        public ?string $versions_url = null,
        /** "sxt_••••a91f" once a token exists; the token itself never reaches the browser. */
        public ?string $versions_token_masked = null,
        /** The OS the versions endpoint reports; the OS choice is locked while set. */
        public ?string $os_reported = null,
    ) {}

    /**
     * @param  string  $ip  prefilled from Add site's unknown address (SPEC §10.4)
     */
    public static function blank(string $ip = ''): self
    {
        return new self(null, '', null, null, null, null, [['ip' => $ip, 'is_primary' => true]], []);
    }

    public static function fromModel(Server $server): self
    {
        return new self(
            id: $server->id,
            name: $server->name,
            hostname: $server->hostname,
            provider: $server->provider,
            os_choice_id: $server->os_choice_id,
            notes: $server->notes,
            addresses: array_values($server->addresses->map(fn (ServerAddress $address): array => ['ip' => $address->ip, 'is_primary' => $address->is_primary])->all()),
            backup_location_ids: array_values($server->backupLocations->map(fn (Choice $choice): int => $choice->id)->all()),
            versions_url: $server->versions_url,
            versions_token_masked: ServerVersionsController::maskedToken($server),
            os_reported: $server->osIsReported() ? $server->os_reported : null,
        );
    }
}
