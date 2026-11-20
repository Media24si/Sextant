<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerAddress;

/**
 * Address changes are recorded on the server (SPEC §8.4).
 */
class ServerAddressObserver extends InventoryObserver
{
    public function created(ServerAddress $address): void
    {
        $this->added($address->server, $address->ip, $address->is_primary);
    }

    public function updated(ServerAddress $address): void
    {
        $server = $address->server;

        if ($address->wasChanged('ip')) {
            $this->removed($server, (string) $address->getOriginal('ip'));
            $this->added($server, $address->ip, $address->is_primary);

            return;
        }

        if ($address->wasChanged('is_primary') && $address->is_primary) {
            // SPEC: §8.4 has no type for a new primary address; it's a server update.
            $this->record('server.updated', $server, "Server {$server->name} changed: primary address {$address->ip}", [
                'primary_address' => ['old' => null, 'new' => $address->ip],
            ]);
        }
    }

    public function deleted(ServerAddress $address): void
    {
        $this->removed($address->server, $address->ip);
    }

    private function added(Server $server, string $ip, bool $primary): void
    {
        $this->record('server.address_added', $server, "{$server->name}: address {$ip} added".($primary ? ' (primary)' : ''), [
            'ip' => $ip,
            'primary' => $primary,
        ]);
    }

    private function removed(Server $server, string $ip): void
    {
        $this->record('server.address_removed', $server, "{$server->name}: address {$ip} removed", ['ip' => $ip]);
    }
}
