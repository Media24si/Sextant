<?php

namespace App\Checks\Server;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Checks\ServerChecker;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Net\TcpProbe;
use Illuminate\Database\Eloquent\Model;

/**
 * `server.tcp`: can a TCP connection be made to a port (SPEC §9.1)? The
 * authoritative reachability check and the only parent for grouping (§6.5).
 */
class TcpChecker extends ServerChecker
{
    public function __construct(private TcpProbe $tcp) {}

    public static function name(): string
    {
        return 'TCP port';
    }

    public static function arguments(): array
    {
        return [
            Argument::int('port', 'Port')->rules(['required', 'between:1,65535']),
            Argument::select('address', 'Address', options: fn (Model $server): array => $server instanceof Server
                ? $server->addresses->mapWithKeys(fn (ServerAddress $address): array => [$address->ip => $address->ip])->all()
                : [])
                ->nullable()->help('Empty = primary address'),
            Argument::int('timeout', 'Timeout (seconds)')->rules(['between:1,30']),
        ];
    }

    protected function check(Server $server, Arguments $arguments): CheckResult
    {
        $address = $arguments->nullableString('address') ?? $server->primaryAddress->ip
            ?? throw new CheckCouldNotRun("{$server->name} has no primary address");
        $port = $arguments->int('port');
        $timeout = $arguments->int('timeout');

        $result = $this->tcp->connect($address, $port, $timeout);
        $data = ['address' => $address, 'port' => $port, 'connect_ms' => $result->ok ? $result->ms : null];

        if ($result->ok) {
            return CheckResult::ok("Port {$port} open ({$result->ms} ms)", $data);
        }

        $error = strtolower((string) $result->error);

        return CheckResult::error(match (true) {
            str_contains($error, 'refused') => "Port {$port} refused",
            str_contains($error, 'timed out') || str_contains($error, 'timeout') => "Port {$port} timed out after {$timeout} s",
            default => "Port {$port} unreachable: {$result->error}",
        }, $data);
    }
}
