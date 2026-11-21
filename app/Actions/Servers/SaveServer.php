<?php

namespace App\Actions\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Saves a server with its addresses and backup locations in one transaction.
 * Each change goes through the observers, so the feed shows exactly what the
 * admin changed (SPEC §8.2).
 */
class SaveServer
{
    /**
     * @param  array{name: string, hostname?: string|null, provider?: string|null, os_choice_id?: int|null, notes?: string|null, versions_url?: string|null, addresses: list<array{ip: string, is_primary: bool|string|int}>, backup_location_ids?: list<int>}  $input
     */
    public function handle(Server $server, array $input): Server
    {
        return DB::transaction(function () use ($server, $input): Server {
            $server->fill([
                'name' => $input['name'],
                'hostname' => $input['hostname'] ?? null,
                'provider' => $input['provider'] ?? null,
                'os_choice_id' => $input['os_choice_id'] ?? null,
                'notes' => $input['notes'] ?? null,
                'versions_url' => $input['versions_url'] ?? null,
            ])->save();

            $this->syncAddresses($server, $input['addresses']);
            $server->syncBackupLocations(array_map('intval', $input['backup_location_ids'] ?? []));

            return $server;
        });
    }

    /**
     * @param  list<array{ip: string, is_primary: bool|string|int}>  $addresses
     */
    private function syncAddresses(Server $server, array $addresses): void
    {
        $wanted = [];

        foreach ($addresses as $address) {
            $ip = (string) inet_ntop((string) inet_pton(trim($address['ip'])));
            $wanted[$ip] = filter_var($address['is_primary'], FILTER_VALIDATE_BOOL);
        }

        $existing = $server->addresses()->get()->keyBy('ip');

        foreach ($existing as $ip => $address) {
            if (! array_key_exists($ip, $wanted)) {
                $address->delete();
            }
        }

        // Demote before promoting, so there's never more than one primary.
        foreach ($existing as $ip => $address) {
            if (array_key_exists($ip, $wanted) && $address->is_primary && ! $wanted[$ip]) {
                $address->update(['is_primary' => false]);
            }
        }

        foreach ($wanted as $ip => $primary) {
            $address = $existing->get($ip);

            if ($address === null) {
                $server->addresses()->create(['ip' => $ip, 'is_primary' => $primary]);
            } elseif ($address->is_primary !== $primary) {
                $address->update(['is_primary' => $primary]);
            }
        }
    }
}
