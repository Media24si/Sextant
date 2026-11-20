<?php

namespace App\Net\Fake;

use App\Checks\CheckCouldNotRun;
use App\Net\RdapClient;
use App\Net\RdapParser;
use App\Net\Results\RdapDomain;

/** Canned RDAP records for tests. Unknown domains: the TLD has no RDAP service. */
class FakeRdapClient implements RdapClient
{
    /** @var array<string, RdapDomain|CheckCouldNotRun> */
    private array $records = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(RdapClient::class, $fake);

        return $fake;
    }

    public function record(string $name, RdapDomain $record): self
    {
        $this->records[$name] = $record;

        return $this;
    }

    /**
     * A recorded response from tests/Fixtures/rdap.
     */
    public function fixture(string $name, string $fixture, string $server = 'rdap.example'): self
    {
        $json = json_decode((string) file_get_contents(base_path("tests/Fixtures/rdap/{$fixture}")), true);

        return $this->record($name, RdapParser::parse(is_array($json) ? $json : [], $server));
    }

    public function notFound(string $name, string $server = 'rdap.example'): self
    {
        return $this->record($name, new RdapDomain(found: false, server: $server));
    }

    public function fail(string $name, string $message = 'RDAP rate limit reached'): self
    {
        $this->records[$name] = new CheckCouldNotRun($message);

        return $this;
    }

    public function domain(string $name, bool $fresh = false): ?RdapDomain
    {
        $record = $this->records[$name] ?? null;

        if ($record instanceof CheckCouldNotRun) {
            throw $record;
        }

        return $record;
    }

    public function refreshBootstrap(): void {}
}
