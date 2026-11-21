<?php

use App\Detection\InvalidUrl;
use App\Detection\SiteDetector;
use App\Enums\Confidence;
use App\Enums\Status;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Net\DnsResolver;
use App\Net\HttpProbe;
use App\Net\RdapClient;
use App\Net\TlsProbe;
use App\Net\WhoisClient;

/*
 * SiteDetector against faked DNS, HTTP, TLS and RDAP (SPEC §10.1–§10.3, §17).
 * The fixture world: web02 (192.0.2.10) and web03 (192.0.2.11) are ours,
 * Varnish (203.0.113.80) is an external service, svet24.si is in Sextant.
 */
beforeEach(function () {
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    ServerAddress::factory()->for($this->web02)->primary()->create(['ip' => '192.0.2.10']);
    $this->web03 = Server::factory()->create(['name' => 'web03']);
    ServerAddress::factory()->for($this->web03)->primary()->create(['ip' => '192.0.2.11']);
    $this->varnish = ExternalService::factory()->create(['name' => 'Varnish', 'ranges' => ['203.0.113.80']]);
    $this->svet24 = Domain::factory()->create(['name' => 'svet24.si']);

    $this->dns = app(DnsResolver::class);
    $this->http = app(HttpProbe::class);
    $this->tls = app(TlsProbe::class);
});

function detect(string $url, ?Site $ignore = null)
{
    return app(SiteDetector::class)->detect($url, $ignore);
}

function siteBehind(Domain $domain, ?ExternalService $front, ?Server $server, ?string $hostname = null): Site
{
    return Site::factory()->for($domain)->create(array_filter([
        'hostname' => $hostname,
        'front_service_id' => $front?->id,
        'server_id' => $server?->id,
    ]));
}

it('detects a site that points straight at one of our servers', function () {
    $this->dns->answer('podjetje.svet24.si', ['192.0.2.10']);
    $this->http->respond('https://podjetje.svet24.si/', 200, ms: 184);
    $this->tls->valid('podjetje.svet24.si', expires: '+74 days');

    $result = detect('Podjetje.Svet24.si.');

    expect($result->hostname)->toBe('podjetje.svet24.si')
        ->and($result->domain->value->existing_id)->toBe($this->svet24->id)
        ->and($result->dns->confidence)->toBe(Confidence::Detected)
        ->and($result->front->value)->toBeNull()
        ->and($result->front->confidence)->toBe(Confidence::Detected)
        ->and($result->backend->value->server_id)->toBe($this->web02->id)
        ->and($result->backend->confidence)->toBe(Confidence::Detected)
        ->and($result->backend->explanation)->toBe("192.0.2.10 is web02's.")
        ->and($result->probe->http)->toBe('200 in 184 ms')
        ->and($result->probe->http_status)->toBe(Status::Ok)
        ->and($result->probe->tls)->toContain("Let's Encrypt · valid until")->toContain('(74 days)')
        ->and($result->probe->redirect)->toBe('No redirect')
        ->and(collect($result->monitors)->pluck('checker')->all())->toBe(['site.http', 'site.ssl', 'site.path']);
});

it('detects Cloudflare by its published ranges and suggests the siblings\' backend', function () {
    Cache::forever('published-ranges', ['cloudflare' => ['104.16.0.0/13']]);
    $cloudflare = ExternalService::query()->where('name', 'Cloudflare')->sole();
    siteBehind($this->svet24, $cloudflare, $this->web03);
    $this->dns->answer('shop.svet24.si', ['104.21.5.9']);

    $result = detect('shop.svet24.si');

    expect($result->front->value->name)->toBe('Cloudflare')
        ->and($result->front->explanation)->toBe('104.21.5.9 belongs to the external service Cloudflare.')
        ->and($result->backend->value->name)->toBe('web03')
        ->and($result->backend->confidence)->toBe(Confidence::Suggested)
        ->and($result->backend->explanation)->toBe("Sextant can't see behind Cloudflare. The other svet24.si site behind Cloudflare uses web03.")
        ->and($result->unknown_ip)->toBeNull();
});

it('suggests the most common backend behind Varnish', function () {
    siteBehind($this->svet24, $this->varnish, $this->web02);
    siteBehind($this->svet24, $this->varnish, $this->web02);
    siteBehind($this->svet24, $this->varnish, $this->web02);
    siteBehind($this->svet24, $this->varnish, $this->web03);
    siteBehind($this->svet24, $this->varnish, null);
    siteBehind($this->svet24, null, $this->web03);
    $this->dns->answer('vestnik.svet24.si', ['203.0.113.80']);

    $result = detect('https://vestnik.svet24.si');

    expect($result->backend->value->name)->toBe('web02')
        ->and($result->backend->confidence)->toBe(Confidence::Suggested)
        ->and($result->backend->explanation)->toBe("Sextant can't see behind Varnish. 3 of the 5 svet24.si sites behind Varnish use web02.")
        ->and(collect($result->backend_candidates)->map(fn ($candidate) => [$candidate->name, $candidate->sibling_count])->all())
        ->toBe([['web02', 3], ['web03', 1]]);
});

it('picks no backend when the siblings are split evenly', function () {
    siteBehind($this->svet24, $this->varnish, $this->web02);
    siteBehind($this->svet24, $this->varnish, $this->web03);
    $this->dns->answer('vestnik.svet24.si', ['203.0.113.80']);

    $result = detect('vestnik.svet24.si');

    expect($result->backend->value)->toBeNull()
        ->and($result->backend->confidence)->toBe(Confidence::NotFound)
        ->and($result->backend->explanation)->toContain('split between web02 and web03')
        ->and($result->backend_candidates)->toHaveCount(2);
});

it('has no suggestion without siblings behind the same front', function () {
    siteBehind($this->svet24, null, $this->web02);
    $this->dns->answer('vestnik.svet24.si', ['203.0.113.80']);

    $result = detect('vestnik.svet24.si');

    expect($result->backend->confidence)->toBe(Confidence::NotFound)
        ->and($result->backend->explanation)->toContain('No other svet24.si site behind it has a backend yet')
        ->and($result->backend_candidates)->toBe([]);
});

it('reads the backend header when one is configured', function (?string $header, Confidence $expected) {
    config(['sextant.detection.backend_header' => $header]);
    siteBehind($this->svet24, $this->varnish, $this->web02);
    $this->dns->answer('vestnik.svet24.si', ['203.0.113.80']);
    $this->http->respond('https://vestnik.svet24.si/', 200, ['X-Backend' => 'web03']);

    $result = detect('vestnik.svet24.si');

    expect($result->backend->confidence)->toBe($expected)
        ->and($result->backend->value->name)->toBe($expected === Confidence::Detected ? 'web03' : 'web02');
})->with([
    'header on' => ['X-Backend', Confidence::Detected],
    'header off' => [null, Confidence::Suggested],
]);

it('offers a new server or external service for an unknown address', function () {
    $this->dns->answer('novo.svet24.si', ['198.51.100.7']);

    $result = detect('novo.svet24.si');

    expect($result->unknown_ip)->toBe('198.51.100.7')
        ->and($result->front->confidence)->toBe(Confidence::NotFound)
        ->and($result->backend->confidence)->toBe(Confidence::NotFound)
        ->and($result->backend->explanation)->toBe("198.51.100.7 isn't one of our servers. Add it as a server, or pick one.");
});

it('lists both servers when DNS points at two of ours', function () {
    $this->dns->answer('lb.svet24.si', ['192.0.2.10', '192.0.2.11']);

    $result = detect('lb.svet24.si');

    expect($result->backend->confidence)->toBe(Confidence::NotFound)
        ->and(collect($result->backend_candidates)->pluck('name')->all())->toBe(['web02', 'web03']);
});

it('carries on when the hostname does not resolve', function () {
    $result = detect('nowhere.svet24.si');

    expect($result->dns->confidence)->toBe(Confidence::NotFound)
        ->and($result->dns->explanation)->toBe("nowhere.svet24.si does not resolve. Sextant carries on, but can't tell what's in front.")
        ->and($result->front->confidence)->toBe(Confidence::NotFound)
        ->and($result->backend->confidence)->toBe(Confidence::NotFound)
        ->and($result->probe->http_status)->toBe(Status::Error);
});

it('previews the registry for a new domain and proposes its checks', function () {
    app(RdapClient::class)->fixture('example.com', 'example.com.json', 'https://rdap.verisign.com/com/v1/');
    $this->dns->answer('shop.example.com', ['192.0.2.10']);

    $result = detect('shop.example.com');

    expect($result->domain->value->existing_id)->toBeNull()
        ->and($result->domain->value->name)->toBe('example.com')
        ->and($result->domain->value->registry->source)->toBe('rdap')
        ->and($result->domain->value->registry->server)->toBe('rdap.verisign.com')
        ->and($result->domain->value->registry->expires_at)->toBe('2027-08-13')
        ->and($result->domain->value->registry->nameservers)->toBe(['elliott.ns.cloudflare.com', 'hera.ns.cloudflare.com'])
        ->and(collect($result->monitors)->pluck('checker')->all())->toContain('domain.expiration');
});

it('falls back to WHOIS for a TLD without RDAP', function () {
    app(WhoisClient::class)->respond('primer.me', (string) file_get_contents(base_path('tests/Fixtures/whois/nic.me.txt')));
    $this->dns->answer('primer.me', ['192.0.2.10']);

    $registry = detect('primer.me')->domain->value->registry;

    expect($registry->source)->toBe('whois')
        ->and($registry->found)->toBeTrue()
        ->and($registry->expires_at)->toBe('2027-04-29');
});

it('keeps going when the registry lookup fails', function () {
    app(RdapClient::class)->fail('example.com');
    $this->dns->answer('example.com', ['192.0.2.10']);

    expect(detect('example.com')->domain->value->registry->error)->toBe('RDAP rate limit reached');
});

it('records a redirect as the expected target and offers the www site', function () {
    $this->dns->answer('oglasi24.si', ['192.0.2.10']);
    $this->http->respond('https://oglasi24.si/', 200, redirectsTo: 'https://www.oglasi24.si/?utm=1');

    $result = detect('oglasi24.si');

    expect($result->probe->redirect)->toBe('Redirects to https://www.oglasi24.si/?utm=1')
        ->and($result->probe->redirect_status)->toBe(Status::Ok)
        ->and($result->offer_www)->toBe('www.oglasi24.si');

    // SPEC: site.redirect is registered in Phase 8; until then the target isn't proposed.
    expect(collect($result->monitors)->pluck('checker'))->not->toContain('site.redirect');
});

it('keeps a non-root path for the HTTP check', function () {
    $this->dns->answer('podjetje.svet24.si', ['192.0.2.10']);

    $result = detect('http://podjetje.svet24.si/novice?x=1');

    expect($result->path)->toBe('/novice?x=1')
        ->and($result->scheme)->toBe('http')
        ->and($result->probe->tls)->toBeNull()
        ->and(collect($result->monitors)->firstWhere('checker', 'site.http')->arguments)->toBe(['path' => '/novice?x=1'])
        ->and(collect($result->monitors)->pluck('checker'))->not->toContain('site.ssl');
});

it('stops at a duplicate and says whether it can be restored', function (bool $trashed) {
    $site = siteBehind($this->svet24, $this->varnish, $this->web02, 'podjetje.svet24.si');

    if ($trashed) {
        $site->delete();
    }

    $result = detect('podjetje.svet24.si');

    expect($result->duplicate->site_id)->toBe($site->id)
        ->and($result->duplicate->trashed)->toBe($trashed)
        ->and($result->duplicate->serving_path)->toBe('Varnish → web02')
        ->and($result->domain)->toBeNull()
        ->and(app(DnsResolver::class)->resolved)->toBe([]);
})->with(['live' => false, 'deleted' => true]);

it('ignores the site itself when detection runs again on it', function () {
    $site = siteBehind($this->svet24, $this->varnish, $this->web02, 'podjetje.svet24.si');
    $this->dns->answer('podjetje.svet24.si', ['203.0.113.80']);

    $result = detect('podjetje.svet24.si', $site);

    expect($result->duplicate)->toBeNull()
        ->and($result->backend->confidence)->toBe(Confidence::NotFound);
});

it('refuses what is not a site URL', function (string $input) {
    detect($input);
})->with(['192.0.2.10', 'localhost', 'ftp://svet24.si', 'co.uk'])->throws(InvalidUrl::class);
