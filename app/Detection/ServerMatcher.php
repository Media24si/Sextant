<?php

namespace App\Detection;

use App\Models\Server;
use App\Models\ServerAddress;
use Illuminate\Support\Collection;

/** IP → the non-deleted server that owns it (SPEC §9.0). */
class ServerMatcher
{
    public function forIp(string $ip): ?Server
    {
        $canonical = ($packed = @inet_pton($ip)) !== false ? (string) inet_ntop($packed) : $ip;

        return ServerAddress::query()->where('ip', $canonical)->whereHas('server')->with('server')->first()?->server;
    }

    /**
     * The distinct servers owning any of the addresses.
     *
     * @param  list<string>  $ips
     * @return Collection<int, Server>
     */
    public function forIps(array $ips): Collection
    {
        return collect($ips)
            ->map(fn (string $ip): ?Server => $this->forIp($ip))
            ->filter()
            ->unique('id')
            ->values();
    }
}
