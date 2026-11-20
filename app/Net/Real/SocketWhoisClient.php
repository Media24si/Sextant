<?php

namespace App\Net\Real;

use App\Checks\CheckCouldNotRun;
use App\Net\WhoisClient;
use Illuminate\Support\Facades\Cache;

/**
 * WHOIS on port 43 with a 10-second timeout. The server comes from
 * whois.iana.org's `refer:` line, cached (SPEC §9.0).
 */
class SocketWhoisClient implements WhoisClient
{
    private const TIMEOUT = 10;

    public function query(string $domain): string
    {
        $tld = substr($domain, (int) strrpos($domain, '.') + 1);
        $server = Cache::rememberForever("whois.server.{$tld}", function () use ($tld): string {
            $referral = $this->ask('whois.iana.org', $tld);

            if (preg_match('/^(?:refer|whois):\s*(\S+)/mi', $referral, $match) !== 1) {
                throw new CheckCouldNotRun("No WHOIS server for .{$tld}");
            }

            return $match[1];
        });

        return $this->ask($server, $domain);
    }

    private function ask(string $server, string $query): string
    {
        $socket = @stream_socket_client("tcp://{$server}:43", $code, $message, self::TIMEOUT);

        if ($socket === false) {
            throw new CheckCouldNotRun("WHOIS server {$server} didn't answer: {$message}");
        }

        stream_set_timeout($socket, self::TIMEOUT);
        fwrite($socket, $query."\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        if ($response === false || $response === '') {
            throw new CheckCouldNotRun("Empty WHOIS response from {$server}");
        }

        return $response;
    }
}
