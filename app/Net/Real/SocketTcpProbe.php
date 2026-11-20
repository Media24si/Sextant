<?php

namespace App\Net\Real;

use App\Net\Results\TcpResult;
use App\Net\TcpProbe;

class SocketTcpProbe implements TcpProbe
{
    public function connect(string $ip, int $port, float $timeout = 5): TcpResult
    {
        $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $start = hrtime(true);

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $code, $message, $timeout);
        $ms = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($socket === false) {
            return new TcpResult(false, $ms, $message !== '' ? $message : "error {$code}");
        }

        fclose($socket);

        return new TcpResult(true, $ms);
    }
}
