<?php

namespace App\Net\Fake;

use App\Checks\CheckCouldNotRun;
use App\Net\DnsResolver;
use App\Net\Results\DnsAnswer;
use App\Net\Results\SoaRecord;

/** Canned DNS for tests. Unknown names don't exist (NXDOMAIN). */
class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, DnsAnswer|CheckCouldNotRun> */
    private array $answers = [];

    /** @var array<string, list<string>> */
    private array $nameservers = [];

    /** @var list<string> */
    public array $resolved = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(DnsResolver::class, $fake);

        return $fake;
    }

    /**
     * @param  list<string>  $ips  IPv4 and IPv6 addresses
     * @param  list<string>  $cnames
     */
    public function answer(string $host, array $ips, array $cnames = []): self
    {
        $this->answers[$host] = new DnsAnswer(
            exists: true,
            cnameChain: $cnames,
            a: array_values(array_filter($ips, fn (string $ip) => ! str_contains($ip, ':'))),
            aaaa: array_values(array_filter($ips, fn (string $ip) => str_contains($ip, ':'))),
        );

        return $this;
    }

    public function fail(string $host, string $message = 'DNS lookup timed out'): self
    {
        $this->answers[$host] = new CheckCouldNotRun($message);

        return $this;
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function nameservers(string $domain, array $nameservers): self
    {
        $this->nameservers[$domain] = $nameservers;

        return $this;
    }

    public function resolve(string $host): DnsAnswer
    {
        $this->resolved[] = $host;
        $answer = $this->answers[$host] ?? new DnsAnswer(exists: false);

        if ($answer instanceof CheckCouldNotRun) {
            throw $answer;
        }

        return $answer;
    }

    public function ns(string $domain): array
    {
        return $this->nameservers[$domain] ?? [];
    }

    public function soa(string $domain, string $nameserver): ?SoaRecord
    {
        return null;
    }
}
