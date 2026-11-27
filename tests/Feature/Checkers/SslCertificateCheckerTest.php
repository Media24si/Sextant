<?php

use App\Checks\CheckCouldNotRun;
use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Enums\Status;
use App\Models\ExternalService;
use App\Models\Monitor;
use App\Models\Site;
use App\Net\TlsProbe;

/* `site.ssl` (SPEC §9.6). */
function sslCheck(Site $site): CheckResult
{
    $registry = app(CheckerRegistry::class);

    return $registry->checker('site.ssl')->run($site, $registry->settings(Monitor::factory()->on($site, 'site.ssl')->make())->arguments);
}

beforeEach(function () {
    $this->travelTo(now()->setTimezone('Europe/Ljubljana')->setTime(12, 0));
    $this->site = Site::factory()->create();
});

it('grades the days left', function (string $expires, Status $status, string $message) {
    app(TlsProbe::class)->valid($this->site->hostname, expires: $expires);

    $result = sslCheck($this->site);

    expect($result->status)->toBe($status)
        ->and($result->message)->toContain($message)
        ->and($result->data)->toHaveKeys(['fingerprint_sha256', 'issuer', 'days_remaining', 'chain_valid']);
})->with([
    '100 days' => ['+100 days', Status::Ok, "(100 days), Let's Encrypt"],
    '20 days' => ['+20 days', Status::Ok, '(20 days)'],
    '10 days' => ['+10 days', Status::Warning, '(10 days)'],
    '5 days' => ['+5 days', Status::Error, '(5 days)'],
    'expired' => ['-1 day', Status::Error, 'Expired on'],
]);

it('is an error when verification fails', function () {
    app(TlsProbe::class)->invalid($this->site->hostname, "The certificate doesn't cover {$this->site->hostname}");

    expect(sslCheck($this->site))->status->toBe(Status::Error)->message->toBe("Certificate not valid: The certificate doesn't cover {$this->site->hostname}");
});

it('names the edge certificate behind a front service', function () {
    $this->site->update(['front_service_id' => ExternalService::query()->where('name', 'Cloudflare')->value('id')]);
    app(TlsProbe::class)->valid($this->site->hostname, expires: '+100 days');

    expect(sslCheck($this->site->fresh())->message)->toEndWith('(edge certificate, Cloudflare)');
});

it('leaves a refused connection to the HTTP check', function () {
    sslCheck($this->site);
})->throws(CheckCouldNotRun::class);
