<?php

namespace App\Checks\Conditions;

use App\Checks\Server\PingChecker;
use App\Models\Server;
use App\Net\PingProbe;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** `server.ping` only for servers that answer a ping (SPEC §4.4). */
class RespondsToPing implements Condition
{
    public function __construct(private PingProbe $ping) {}

    public function __invoke(Model $resource, array $arguments): bool
    {
        if (! $resource instanceof Server) {
            return false;
        }

        $address = PingChecker::addressOf($resource);

        if ($address === null) {
            return false;
        }

        try {
            return $this->ping->ping($address, 2, 3)->lossPct < 100;
        } catch (Throwable) {
            return false;
        }
    }
}
