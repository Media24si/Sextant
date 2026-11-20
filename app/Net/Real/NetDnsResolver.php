<?php

namespace App\Net\Real;

use App\Checks\CheckCouldNotRun;
use App\Net\DnsResolver;
use App\Net\Results\DnsAnswer;
use App\Net\Results\SoaRecord;
use NetDNS2\ENUM\Error;
use NetDNS2\Exception as DnsException;
use NetDNS2\Packet\Response;
use NetDNS2\Resolver;
use NetDNS2\RR;
use NetDNS2\RR\A;
use NetDNS2\RR\AAAA;
use NetDNS2\RR\CNAME;
use NetDNS2\RR\NS;
use NetDNS2\RR\SOA;

/**
 * Net_DNS2 v2 against config('sextant.dns.resolvers'). Record fields are
 * value objects in v2, so they're cast to strings (docs/spikes/net-dns2.md).
 */
class NetDnsResolver implements DnsResolver
{
    public function resolve(string $host): DnsAnswer
    {
        try {
            $a = $this->query($host, 'A');
        } catch (DnsException $exception) {
            if ($exception->getCode() === Error::DNS_NXDOMAIN->value) {
                return new DnsAnswer(exists: false);
            }

            throw $this->couldNotRun($host, $exception);
        }

        try {
            $aaaa = $this->query($host, 'AAAA');
        } catch (DnsException) {
            $aaaa = null;
        }

        return new DnsAnswer(
            exists: true,
            cnameChain: $this->cnameChain($a),
            a: $this->records($a, A::class, fn (A $record): string => $this->field($record, 'address')),
            aaaa: $aaaa === null ? [] : $this->records($aaaa, AAAA::class, fn (AAAA $record): string => $this->field($record, 'address')),
        );
    }

    public function ns(string $domain): array
    {
        try {
            $response = $this->query($domain, 'NS');
        } catch (DnsException $exception) {
            if ($exception->getCode() === Error::DNS_NXDOMAIN->value) {
                return [];
            }

            throw $this->couldNotRun($domain, $exception);
        }

        return $this->records($response, NS::class, fn (NS $record): string => $this->name($this->field($record, 'nsdname')));
    }

    public function soa(string $domain, string $nameserver): ?SoaRecord
    {
        try {
            $ip = filter_var($nameserver, FILTER_VALIDATE_IP) !== false
                ? $nameserver
                : ($this->resolve($nameserver)->a[0] ?? null);

            if ($ip === null) {
                return null;
            }

            $response = $this->resolver([$ip], recurse: false)->query($domain, 'SOA');
        } catch (DnsException $exception) {
            throw $this->couldNotRun($domain, $exception);
        }

        foreach ($response->answer as $record) {
            if ($record instanceof SOA) {
                return new SoaRecord((int) $this->field($record, 'serial'), $this->name($this->field($record, 'mname')));
            }
        }

        return null;
    }

    private function query(string $name, string $type): Response
    {
        return $this->resolver(config('sextant.dns.resolvers'))->query($name, $type);
    }

    /**
     * @param  list<string>  $nameservers
     */
    private function resolver(array $nameservers, bool $recurse = true): Resolver
    {
        $resolver = new Resolver([
            'nameservers' => $nameservers,
            'timeout' => (float) config('sextant.dns.timeout'),
        ]);
        $resolver->recurse = $recurse;

        return $resolver;
    }

    /**
     * @template T of RR
     *
     * @param  class-string<T>  $type
     * @param  callable(T): string  $value
     * @return list<string>
     */
    private function records(Response $response, string $type, callable $value): array
    {
        $values = [];

        foreach ($response->answer as $record) {
            if ($record instanceof $type) {
                $values[] = $value($record);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * The CNAMEs in the answer, in order (docs/spikes/net-dns2.md).
     *
     * @return list<string>
     */
    private function cnameChain(Response $response): array
    {
        return $this->records($response, CNAME::class, fn (CNAME $record): string => $this->name($this->field($record, 'cname')));
    }

    /**
     * Record fields are protected in Net_DNS2 v2 and read through its public
     * __get(); they're value objects or scalars, so cast them.
     */
    private function field(RR $record, string $name): string
    {
        $value = $record->__get($name);

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    private function name(string $name): string
    {
        return rtrim(strtolower($name), '.');
    }

    private function couldNotRun(string $name, DnsException $exception): CheckCouldNotRun
    {
        return new CheckCouldNotRun("DNS lookup for {$name} failed: {$exception->getMessage()}", previous: $exception);
    }
}
