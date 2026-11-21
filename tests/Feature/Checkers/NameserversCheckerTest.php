<?php

use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Enums\Status;
use App\Models\Domain;
use App\Models\Monitor;
use App\Net\DnsResolver;
use App\Net\RdapClient;

/* `domain.nameservers` (SPEC §9.4). svet24.si's registry lists dns1–3.media24.si. */
function nameserversCheck(Domain $domain, array $arguments = []): CheckResult
{
    $registry = app(CheckerRegistry::class);
    $monitor = Monitor::factory()->on($domain, 'domain.nameservers')->make(['arguments' => $arguments]);

    return $registry->checker('domain.nameservers')->run($domain, $registry->settings($monitor)->arguments);
}

beforeEach(function () {
    $this->domain = Domain::factory()->create(['name' => 'svet24.si']);
    $this->dns = app(DnsResolver::class);
    app(RdapClient::class)->fixture('svet24.si', 'svet24.si.json');
});

function answeringNameservers(array $serials): void
{
    test()->dns->nameservers('svet24.si', array_map(fn (string $ns) => strtoupper($ns).'.', array_keys($serials)));

    foreach ($serials as $ns => $serial) {
        test()->dns->soaAnswer('svet24.si', $ns, $serial);
    }
}

it('is OK when the live set matches the registry and the serials agree', function () {
    answeringNameservers(['dns1.media24.si' => 2026112001, 'dns2.media24.si' => 2026112001, 'dns3.media24.si' => 2026112001]);

    expect(nameserversCheck($this->domain))
        ->status->toBe(Status::Ok)
        ->message->toBe('3 nameservers, serials consistent')
        ->data->toMatchArray([
            'nameservers_live' => ['dns1.media24.si', 'dns2.media24.si', 'dns3.media24.si'],
            'nameservers_registry' => ['dns1.media24.si', 'dns2.media24.si', 'dns3.media24.si'],
        ]);
});

it('warns when the live set differs from the registry', function () {
    answeringNameservers(['dns1.media24.si' => 1, 'kehlani.ns.cloudflare.com' => 1]);

    expect(nameserversCheck($this->domain))
        ->status->toBe(Status::Warning)
        ->message->toBe('Nameservers differ from the registry: live dns1.media24.si, kehlani.ns.cloudflare.com; expected dns1.media24.si, dns2.media24.si, dns3.media24.si');
});

it('compares with an expected list instead when one is set', function () {
    answeringNameservers(['a.ns.example' => 5, 'b.ns.example' => 5]);

    expect(nameserversCheck($this->domain, ['expected' => ['B.ns.example.', 'a.ns.example']])->status)->toBe(Status::Ok);
});

it('warns about a silent nameserver and differing serials', function () {
    answeringNameservers(['dns1.media24.si' => 7, 'dns2.media24.si' => 8, 'dns3.media24.si' => null]);

    expect(nameserversCheck($this->domain))
        ->status->toBe(Status::Warning)
        ->message->toBe("dns3.media24.si doesn't answer for the zone; SOA serials differ: dns1.media24.si 7, dns2.media24.si 8");
});

it('is an error when no nameserver answers or there are none', function () {
    answeringNameservers(['dns1.media24.si' => null, 'dns2.media24.si' => null, 'dns3.media24.si' => null]);
    expect(nameserversCheck($this->domain))->status->toBe(Status::Error)->message->toBe('No authoritative nameserver answers for the zone');

    $other = Domain::factory()->create(['name' => 'gone.si']);
    expect(nameserversCheck($other))->status->toBe(Status::Error)->message->toBe('No NS records: the domain does not resolve');
});

it('skips the SOA questions when asked to', function () {
    answeringNameservers(['dns1.media24.si' => null, 'dns2.media24.si' => null, 'dns3.media24.si' => null]);

    expect(nameserversCheck($this->domain, ['check_soa' => false]))->status->toBe(Status::Ok)->message->toBe('3 nameservers');
});
