<?php

namespace App\Net\Fake;

use App\Checks\CheckCouldNotRun;
use App\Net\WhoisClient;

/** Canned WHOIS responses for tests. */
class FakeWhoisClient implements WhoisClient
{
    /** @var array<string, string> */
    private array $responses = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(WhoisClient::class, $fake);

        return $fake;
    }

    public function respond(string $domain, string $raw): self
    {
        $this->responses[$domain] = $raw;

        return $this;
    }

    public function query(string $domain): string
    {
        return $this->responses[$domain] ?? throw new CheckCouldNotRun("No WHOIS response for {$domain}");
    }
}
