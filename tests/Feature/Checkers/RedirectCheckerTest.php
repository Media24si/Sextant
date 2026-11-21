<?php

use App\Checks\CheckCouldNotRun;
use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Enums\Status;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\Site;
use App\Net\HttpProbe;
use App\Net\Results\HttpResult;

/* `site.redirect` (SPEC §9.8). */
function redirectCheck(Site $site, array $arguments = []): CheckResult
{
    $registry = app(CheckerRegistry::class);
    $monitor = Monitor::factory()->on($site, 'site.redirect')->make(['arguments' => $arguments]);

    return $registry->checker('site.redirect')->run($site, $registry->settings($monitor)->arguments);
}

/**
 * @param  list<array{0: string, 1: int}>  $hops
 */
function respondWithChain(array $hops, ?string $error = null): void
{
    $chain = array_map(fn (array $hop) => ['url' => $hop[0], 'status' => $hop[1]], $hops);
    $probe = Mockery::mock(HttpProbe::class);
    $probe->shouldReceive('get')->andReturn(new HttpResult($chain, end($hops)[0] ?? null, $error === null ? (end($hops)[1] ?? null) : null, error: $error));
    app()->instance(HttpProbe::class, $probe);
}

beforeEach(function () {
    $this->site = Site::factory()->for(Domain::factory()->create(['name' => 'svet24.si']))->create(['hostname' => 'svet24.si']);
});

it('reports no redirect and a redirect on the same domain as OK', function () {
    respondWithChain([['https://svet24.si/', 200]]);
    expect(redirectCheck($this->site))->status->toBe(Status::Ok)->message->toBe('No redirect');

    respondWithChain([['https://svet24.si/', 301], ['https://www.svet24.si/', 200]]);
    expect(redirectCheck($this->site))->status->toBe(Status::Ok)->message->toBe('Redirects to https://www.svet24.si/')
        ->and(redirectCheck($this->site)->data)->toMatchArray(['final_url' => 'https://www.svet24.si/', 'final_status' => 200]);
});

it('checks the expected target by scheme, host and path, ignoring the query', function () {
    respondWithChain([['https://svet24.si/', 301], ['https://www.svet24.si/?utm=x', 200]]);

    expect(redirectCheck($this->site, ['expected_target' => 'https://www.svet24.si/']))
        ->status->toBe(Status::Ok)->message->toBe('Redirects to https://www.svet24.si/?utm=x as expected')
        ->and(redirectCheck($this->site, ['expected_target' => 'https://svet24.si/novice']))
        ->status->toBe(Status::Warning)->message->toBe('Redirects to https://www.svet24.si/?utm=x, expected https://svet24.si/novice');
});

it('warns when a redirect stops happening', function () {
    respondWithChain([['https://svet24.si/', 200]]);

    expect(redirectCheck($this->site, ['expected_target' => 'https://www.svet24.si/'])->message)->toBe('No redirect, expected https://www.svet24.si/');
});

it('warns about loops, long chains, HTTPS to HTTP and other domains', function (array $hops, array $arguments, string $message) {
    respondWithChain($hops);

    expect(redirectCheck($this->site, $arguments))->status->toBe(Status::Warning)->message->toBe($message);
})->with([
    'loop' => [[['https://svet24.si/', 301], ['https://www.svet24.si/', 301], ['https://svet24.si/', 301]], [], 'Redirect loop: https://svet24.si/ → https://www.svet24.si/ → https://svet24.si/'],
    'too many' => [[['https://svet24.si/', 301], ['https://svet24.si/a', 301], ['https://svet24.si/b', 200]], ['max_redirects' => 1], '2 redirects (more than 1)'],
    'to http' => [[['https://svet24.si/', 301], ['http://svet24.si/', 200]], [], 'Redirects from HTTPS to HTTP: https://svet24.si/ → http://svet24.si/'],
    'another domain' => [[['https://svet24.si/', 302], ['https://parked.example.com/', 200]], [], 'Redirects to another domain: https://parked.example.com/'],
]);

it('cannot run when the site does not answer', function () {
    respondWithChain([], 'Connection refused');

    redirectCheck($this->site);
})->throws(CheckCouldNotRun::class, 'No response: Connection refused');
