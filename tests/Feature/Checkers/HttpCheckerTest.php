<?php

use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Enums\Status;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\Site;
use App\Net\HttpProbe;
use App\Net\Results\HttpResult;

/* `site.http` (SPEC §9.5). */
function httpCheck(Site $site, array $overrides = []): CheckResult
{
    $monitor = Monitor::factory()->on($site, 'site.http')->make(['arguments' => $overrides]);
    $registry = app(CheckerRegistry::class);

    return $registry->checker('site.http')->run($site, $registry->settings($monitor)->arguments);
}

beforeEach(function () {
    $this->site = Site::factory()->create(['hostname' => fn ($attributes) => 'novice.'.Domain::query()->findOrFail($attributes['domain_id'])->name]);
    $this->url = $this->site->url;
});

it('is OK with the expected status in time', function () {
    app(HttpProbe::class)->respond("{$this->url}/", 200, ms: 184, ip: '192.0.2.10');

    expect(httpCheck($this->site))
        ->status->toBe(Status::Ok)
        ->message->toBe('200 in 184 ms')
        ->and(httpCheck($this->site)->data)->toMatchArray(['status_code' => 200, 'total_ms' => 184, 'ip' => '192.0.2.10', 'redirects' => 0]);
});

it('checks the final status after redirects, on the configured path', function () {
    app(HttpProbe::class)->respond("{$this->url}/novice", 404, redirectsTo: "{$this->url}/novice/");

    expect(httpCheck($this->site, ['path' => '/novice']))
        ->status->toBe(Status::Error)
        ->message->toBe('404 in 120 ms (expected 200)')
        ->and(httpCheck($this->site, ['path' => '/novice', 'expected_status' => [200, 404]])->status)->toBe(Status::Ok);
});

it('warns when slow', function () {
    app(HttpProbe::class)->respond("{$this->url}/", 200, ms: 6200);

    expect(httpCheck($this->site))->status->toBe(Status::Warning)->message->toBe('200 in 6200 ms (slow, over 5000 ms)');
});

it('is an error when the site does not answer', function () {
    app(HttpProbe::class)->fail("{$this->url}/", 'Connection timed out after 10 s');

    expect(httpCheck($this->site))->status->toBe(Status::Error)->message->toBe('Connection timed out after 10 s');
});

it('looks for text in the page when asked', function () {
    $probe = Mockery::mock(HttpProbe::class);
    $probe->shouldReceive('get')->withArgs(fn ($url, $follow, $maxBytes) => $maxBytes === 2 * 1024 * 1024)
        ->andReturn(new HttpResult([['url' => "{$this->url}/", 'status' => 200]], "{$this->url}/", 200, timings: ['total' => 90], body: '<h1>Novice</h1>'));
    app()->instance(HttpProbe::class, $probe);

    expect(httpCheck($this->site, ['contains' => 'Novice'])->status)->toBe(Status::Ok)
        ->and(httpCheck($this->site, ['contains' => 'Šport'])->message)->toBe('200, but the page doesn\'t contain "Šport"');
});
