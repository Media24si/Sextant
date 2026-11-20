<?php

namespace App\Net\Results;

/**
 * Where a hostname points (SPEC §9.0 `DnsResolver::resolve`). `exists` is
 * false for NXDOMAIN; empty addresses with `exists` true means no records.
 */
final readonly class DnsAnswer
{
    /**
     * @param  list<string>  $cnameChain  names followed, in order, without trailing dots
     * @param  list<string>  $a
     * @param  list<string>  $aaaa
     */
    public function __construct(
        public bool $exists,
        public array $cnameChain = [],
        public array $a = [],
        public array $aaaa = [],
    ) {}

    /**
     * @return list<string>
     */
    public function ips(): array
    {
        return [...$this->a, ...$this->aaaa];
    }

    public function resolves(): bool
    {
        return $this->exists && $this->ips() !== [];
    }
}
