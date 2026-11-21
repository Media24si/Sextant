<?php

use App\Checks\Arguments;
use App\Checks\CheckResult;
use App\Checks\Site\ServingPathChecker;
use App\Enums\Status;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Net\DnsResolver;
use Illuminate\Support\Facades\Cache;

/*
 * `site.path`: every situation in SPEC §9.7's table. web02 is 192.0.2.10,
 * web03 is 192.0.2.11, Varnish is 203.0.113.80, Cloudflare publishes 104.16.0.0/13.
 */
beforeEach(function () {
    Cache::forever('published-ranges', ['cloudflare' => ['104.16.0.0/13']]);
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    ServerAddress::factory()->for($this->web02)->primary()->create(['ip' => '192.0.2.10']);
    $this->web03 = Server::factory()->create(['name' => 'web03']);
    ServerAddress::factory()->for($this->web03)->primary()->create(['ip' => '192.0.2.11']);
    $this->varnish = ExternalService::factory()->create(['name' => 'Varnish', 'ranges' => ['203.0.113.80']]);
    $this->cloudflare = ExternalService::query()->where('name', 'Cloudflare')->sole();
    $this->domain = Domain::factory()->create(['name' => 'svet24.si']);
});

function pathCheck(Site $site): CheckResult
{
    return app(ServingPathChecker::class)->run($site->fresh(), new Arguments([], []));
}

function siteWithPath(?ExternalService $front, ?Server $backend, string $hostname = 'vestnik.svet24.si'): Site
{
    return Site::factory()->for(test()->domain)->create([
        'hostname' => $hostname,
        'front_service_id' => $front?->id,
        'server_id' => $backend?->id,
    ]);
}

it('judges the path against DNS', function (Closure $front, Closure $backend, array $ips, Status $status, string $message) {
    $site = siteWithPath($front($this), $backend($this));
    app(DnsResolver::class)->answer('vestnik.svet24.si', $ips);

    expect(pathCheck($site))->status->toBe($status)->message->toBe($message);
})->with([
    'via the front' => [fn ($t) => $t->varnish, fn ($t) => $t->web02, ['203.0.113.80'], Status::Ok, 'Via Varnish to web02'],
    'direct' => [fn ($t) => null, fn ($t) => $t->web02, ['192.0.2.10'], Status::Ok, 'Direct to web02'],
    'another service' => [fn ($t) => $t->varnish, fn ($t) => $t->web02, ['104.16.0.5'], Status::Warning, 'Resolves to Cloudflare, path says Varnish'],
    'our server instead of the front' => [fn ($t) => $t->cloudflare, fn ($t) => $t->web02, ['192.0.2.10'], Status::Warning, 'Resolves directly to web02, path says behind Cloudflare'],
    'a service instead of direct' => [fn ($t) => null, fn ($t) => $t->web02, ['104.16.0.5'], Status::Warning, 'Resolves to Cloudflare, path says direct'],
    'another of our servers' => [fn ($t) => null, fn ($t) => $t->web02, ['192.0.2.11'], Status::Warning, 'Resolves to web03, backend is web02'],
    'no backend' => [fn ($t) => null, fn ($t) => null, ['192.0.2.10'], Status::Warning, 'Backend not assigned'],
    'unknown address' => [fn ($t) => null, fn ($t) => $t->web02, ['198.51.100.7'], Status::Warning, 'Resolves to unknown IP 198.51.100.7'],
    'does not resolve' => [fn ($t) => null, fn ($t) => $t->web02, [], Status::Warning, 'Hostname does not resolve'],
]);

it('names the suggested backend when one is missing behind a front', function () {
    siteWithPath($this->varnish, $this->web02, 'svet24.si');
    $site = siteWithPath($this->varnish, null);
    app(DnsResolver::class)->answer('vestnik.svet24.si', ['203.0.113.80']);

    expect(pathCheck($site)->message)->toBe('Backend not assigned (suggested: web02)');
});

it('tracks addresses only when the site is direct', function () {
    $direct = siteWithPath(null, $this->web02);
    $behind = siteWithPath($this->varnish, $this->web02, 'svet24.si');
    app(DnsResolver::class)->answer('vestnik.svet24.si', ['192.0.2.10'])->answer('svet24.si', ['203.0.113.80']);

    expect(pathCheck($direct)->data)->toMatchArray(['ips' => ['192.0.2.10'], 'matched_service' => null, 'matched_servers' => ['web02']])
        ->and(pathCheck($behind)->data)->toMatchArray(['ips' => null, 'matched_service' => 'Varnish']);
});

it('is capped at warning by the registry', function () {
    expect(config('checkers.checkers')['site.path']['max_severity'])->toBe('warning');
});
