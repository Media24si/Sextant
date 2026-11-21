<?php

namespace App\Jobs;

use App\Models\Server;
use App\Monitoring\DefaultMonitors;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A new server's default monitors (SPEC §4.4, §10.7). Queued because
 * `PortIsOpen` probes each port for up to 3 seconds.
 */
class AttachServerMonitors implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server) {}

    public function handle(DefaultMonitors $defaults): void
    {
        if ($this->server->trashed()) {
            return;
        }

        $defaults->attach($this->server->load('primaryAddress'));
    }
}
