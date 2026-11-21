<?php

namespace App\Checks\Conditions;

use App\Models\Server;
use App\Net\TcpProbe;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** A 3-second TCP connection to the server's primary address (SPEC §4.4). */
class PortIsOpen implements Condition
{
    public function __construct(private TcpProbe $tcp) {}

    public function __invoke(Model $resource, array $arguments): bool
    {
        if (! $resource instanceof Server) {
            return false;
        }

        $address = $resource->primaryAddress?->ip;

        if ($address === null) {
            return false;
        }

        try {
            return $this->tcp->connect($address, (int) ($arguments['port'] ?? 0), 3)->ok;
        } catch (Throwable) {
            return false;
        }
    }
}
