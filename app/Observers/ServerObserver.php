<?php

namespace App\Observers;

use App\Models\Server;
use App\Monitoring\MonitorEffects;

class ServerObserver extends InventoryObserver
{
    protected function excluded(): array
    {
        return ['os_reported', 'versions_token', 'versions_token_last4'];
    }

    public function created(Server $server): void
    {
        $this->record('server.created', $server, "Server {$server->name} added");
    }

    public function updated(Server $server): void
    {
        if ($server->wasChanged('versions_token')) {
            $this->record('server.versions_token_rotated', $server, "{$server->name}: versions token rotated");
        }

        $changes = $this->changes($server);

        if ($changes !== []) {
            $this->record('server.updated', $server, "Server {$server->name} changed: ".implode(', ', array_keys($changes)), $changes);
        }
    }

    public function deleted(Server $server): void
    {
        $this->record('server.deleted', $server, "Server {$server->name} deleted");
    }

    public function restored(Server $server): void
    {
        $this->record('server.restored', $server, "Server {$server->name} restored");
        MonitorEffects::runSoon($server);
    }
}
