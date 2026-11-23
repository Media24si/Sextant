<?php

use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Enums\IncidentResolution;
use App\Enums\Status;
use App\Jobs\Heartbeat;
use App\Jobs\SendDiscordMessage;
use App\Models\ExternalService;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Monitoring\IncidentManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * notifications:flush and the Discord sender (SPEC §12.1, §17).
 */
const DISCORD_TEST_WEBHOOK = 'https://discord.test/api/webhooks/1/secret';

beforeEach(function () {
    config(['sextant.notifications.discord_webhook_url' => DISCORD_TEST_WEBHOOK]);
    Http::fake([DISCORD_TEST_WEBHOOK.'*' => Http::response(['id' => '1'])]);
    $this->manager = app(IncidentManager::class);
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    $this->tcp = Monitor::factory()->on($this->web02, 'server.tcp')->create(['label' => 'HTTPS']);
});

function siteIncident(string $status = 'error', ?Server $backend = null, ?string $hostname = null): Incident
{
    $site = Site::factory()->create(array_filter(['server_id' => $backend?->id, 'hostname' => $hostname]));

    return app(IncidentManager::class)->open(
        Monitor::factory()->on($site, 'site.http')->create(),
        $site,
        new CheckResult(Status::from($status), '500 in 80 ms'),
        now(),
    );
}

function flushNotifications(): void
{
    test()->artisan('notifications:flush')->assertSuccessful();
}

/**
 * @return list<array<string, mixed>>
 */
function sentMessages(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values()->all();
}

it('sends an error once it has settled, mentioning nobody', function () {
    $incident = siteIncident();

    flushNotifications();
    Http::assertNothingSent();

    $this->travel(21)->seconds();
    flushNotifications();
    flushNotifications();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '?wait=true')
        && ! isset($request['content'])
        && $request['allowed_mentions'] === ['parse' => []]
        && $request['embeds'][0]['title'] === "🔴 {$incident->title}"
        && $request['embeds'][0]['color'] === 0xE53935
        && $request['embeds'][0]['url'] === route('incidents.show', $incident)
        && $request['embeds'][0]['footer'] === ['text' => 'Sextant']);

    expect($incident->events()->sole()->notified_at)->not->toBeNull()
        ->and($incident->fresh()->last_notified_at)->not->toBeNull();
});

it('mentions nobody for a warning', function () {
    siteIncident('warning');
    $this->travel(21)->seconds();

    flushNotifications();

    Http::assertSent(fn (Request $request) => ! isset($request['content'])
        && $request['allowed_mentions'] === ['parse' => []]
        && str_starts_with($request['embeds'][0]['title'], '🟠 '));
});

it('suppresses muted monitors', function () {
    $incident = siteIncident();
    $incident->monitor->update(['muted_until' => now()->addHour()]);
    $this->travel(21)->seconds();

    flushNotifications();

    Http::assertNothingSent();
    expect($incident->events()->sole()->suppressed_reason)->toBe('muted');
});

it('folds a new outage\'s sites into its embed, with serving paths', function () {
    $varnish = ExternalService::factory()->create(['name' => 'Varnish']);
    $outage = $this->manager->open($this->tcp, $this->web02, CheckResult::error('Port 443 refused'), now());
    $child = siteIncident('error', $this->web02, 'vestnik.svet24.si');
    $child->monitorable->update(['front_service_id' => $varnish->id]);
    $this->travel(21)->seconds();

    flushNotifications();

    Http::assertSentCount(1);
    $embeds = sentMessages()[0]['embeds'];

    expect($embeds)->toHaveCount(1)
        ->and($embeds[0]['title'])->toBe("🔴 {$outage->title}")
        ->and($embeds[0]['description'])->toContain('**Affected sites (1)**', '• `vestnik.svet24.si` — Varnish → web02')
        ->and($child->events()->sole()->notified_at)->not->toBeNull()
        ->and($child->events()->sole()->suppressed_reason)->toBeNull();
});

it('suppresses a child that joins an outage already sent', function () {
    $this->manager->open($this->tcp, $this->web02, CheckResult::error('Port 443 refused'), now());
    $this->travel(21)->seconds();
    flushNotifications();

    $child = siteIncident('error', $this->web02);
    $this->travel(21)->seconds();
    flushNotifications();

    Http::assertSentCount(1);
    expect($child->events()->sole()->suppressed_reason)->toBe('grouped');
});

it('lets a child notify on its own when its parent outage is muted', function () {
    $this->tcp->update(['muted_until' => now()->addHour()]);
    $this->manager->open($this->tcp, $this->web02, CheckResult::error('Port 443 refused'), now());
    $child = siteIncident('error', $this->web02, 'vestnik.svet24.si');
    $this->travel(21)->seconds();

    flushNotifications();

    Http::assertSentCount(1);
    expect($child->parent_id)->not->toBeNull()
        ->and(sentMessages()[0]['embeds'][0]['title'])->toContain('vestnik.svet24.si')
        ->and($child->events()->sole()->suppressed_reason)->toBeNull();
});

it('lets a child wait while its parent\'s opened event settles', function () {
    $this->manager->open($this->tcp, $this->web02, CheckResult::error('Port 443 refused'), now());
    $this->travel(15)->seconds();
    $child = siteIncident('error', $this->web02);
    IncidentEvent::query()->where('incident_id', $child->id)->update(['created_at' => now()->subMinute()]);

    $this->artisan('notifications:flush')->expectsOutputToContain('1 waiting');
    Http::assertNothingSent();
});

it('splits more than ten embeds over several messages', function () {
    foreach (range(1, 12) as $i) {
        siteIncident('warning');
    }
    $this->travel(21)->seconds();

    flushNotifications();

    expect(array_map(fn (array $message) => count($message['embeds']), sentMessages()))->toBe([10, 2]);
});

it('mentions nobody in any message, even one that holds an error', function () {
    foreach (range(1, 10) as $i) {
        siteIncident('warning');
    }
    siteIncident('error');
    $this->travel(21)->seconds();

    flushNotifications();

    expect(array_map(fn (array $message) => [$message['content'] ?? null, $message['allowed_mentions']], sentMessages()))
        ->toBe([[null, ['parse' => []]], [null, ['parse' => []]]]);
});

it('summarises the sites when an outage recovers', function () {
    $outage = $this->manager->open($this->tcp, $this->web02, CheckResult::error('Port 443 refused'), now());
    $recovered = siteIncident('error', $this->web02, 'a.svet24.si');
    siteIncident('error', $this->web02, 'b.svet24.si');
    $this->travel(21)->seconds();
    flushNotifications();

    $this->travel(15)->minutes();
    $this->manager->resolve($recovered, IncidentResolution::Recovered, now());
    $this->manager->resolve($outage, IncidentResolution::Recovered, now());
    $this->travel(21)->seconds();
    flushNotifications();

    $embed = sentMessages()[1]['embeds'][0];

    expect($embed['title'])->toBe("✅ Resolved: {$outage->title} (15 min)")
        ->and($embed['color'])->toBe(0x43A047)
        ->and($embed['description'])->toContain('1 of 2 sites recovered; still failing: b.svet24.si')
        ->and(sentMessages()[1]['allowed_mentions'])->toBe(['parse' => []]);
});

it('counts sites, not incidents, when an outage recovers', function () {
    $outage = $this->manager->open($this->tcp, $this->web02, CheckResult::error('Port 443 refused'), now());
    $http = siteIncident('error', $this->web02, 'a.svet24.si');
    $ssl = $this->manager->open(Monitor::factory()->on($http->monitorable, 'site.ssl')->create(), $http->monitorable, CheckResult::error('Expired'), now());
    siteIncident('error', $this->web02, 'b.svet24.si');
    $this->travel(21)->seconds();
    flushNotifications();

    $this->manager->resolve($http, IncidentResolution::Recovered, now());
    $this->manager->resolve($outage, IncidentResolution::Recovered, now());
    $this->travel(21)->seconds();
    flushNotifications();

    expect($ssl->fresh()->parent_id)->toBe($outage->id)
        ->and(sentMessages()[1]['embeds'][0]['description'])->toContain('0 of 2 sites recovered; still failing: a.svet24.si, b.svet24.si');
});

it('sends acknowledgements and reminders', function () {
    $incident = siteIncident();
    $this->travel(21)->seconds();
    flushNotifications();

    $this->manager->acknowledge($incident, User::factory()->admin()->create(['name' => 'Ana Novak']));
    $this->manager->remind($incident, 'Still failing after the mute ended', now());
    $this->travel(21)->seconds();
    flushNotifications();

    $titles = collect(sentMessages()[1]['embeds'])->pluck('title')->all();

    expect($titles)->toBe(["👀 Ana Novak acknowledged {$incident->title}", "⏰ Still failing: {$incident->title} (42 s)"])
        ->and(sentMessages()[1]['allowed_mentions'])->toBe(['parse' => []]);
});

it('marks events without a webhook so the outbox does not grow', function () {
    config(['sextant.notifications.discord_webhook_url' => null]);
    $incident = siteIncident();
    $this->travel(21)->seconds();

    flushNotifications();

    Http::assertNothingSent();
    expect($incident->events()->sole()->suppressed_reason)->toBe('no_webhook');
});

it('waits out a 429 and fails loudly on other errors', function () {
    // Another webhook, so the default fake from beforeEach doesn't answer first.
    config(['sextant.notifications.discord_webhook_url' => 'https://discord.test/api/webhooks/2/other']);
    Http::fake(['https://discord.test/api/webhooks/2/*' => Http::sequence()
        ->push(['retry_after' => 1.5], 429)
        ->push('Bad Request', 400)]);
    $payload = ['embeds' => [['title' => 'x']], 'allowed_mentions' => ['parse' => []]];

    $job = (new SendDiscordMessage($payload))->withFakeQueueInteractions();
    $job->handle();
    $job->assertReleased(delay: 2);

    $refused = (new SendDiscordMessage($payload))->withFakeQueueInteractions();
    $refused->handle();
    $refused->assertFailedWith(RuntimeException::class);
    expect((new SendDiscordMessage($payload))->maxExceptions)->toBe(5);
});

it('throws on a server error so the queue retries it', function () {
    config(['sextant.notifications.discord_webhook_url' => 'https://discord.test/api/webhooks/2/other']);
    Http::fake(['https://discord.test/api/webhooks/2/*' => Http::response('Bad Gateway', 502)]);

    expect(fn () => (new SendDiscordMessage(['embeds' => [], 'allowed_mentions' => ['parse' => []]]))->handle())
        ->toThrow(RuntimeException::class, 'Discord answered HTTP 502');
});

it('tells the job which events each message carries', function () {
    Queue::fake();
    $first = siteIncident();
    $second = siteIncident('warning');
    $this->travel(21)->seconds();

    flushNotifications();

    Queue::assertPushed(SendDiscordMessage::class, fn (SendDiscordMessage $job): bool => $job->eventIds === [
        $first->events()->sole()->id,
        $second->events()->sole()->id,
    ]);
});

it('puts the events back in the outbox when sending finally fails', function () {
    Queue::fake();
    $incident = siteIncident();
    $this->travel(21)->seconds();
    flushNotifications();
    $event = $incident->events()->sole();
    expect($event->notified_at)->not->toBeNull();

    (new SendDiscordMessage(['embeds' => [], 'allowed_mentions' => ['parse' => []]], [$event->id]))
        ->failed(new RuntimeException('Discord answered HTTP 503', 503));

    expect($event->fresh()->notified_at)->toBeNull();

    flushNotifications();

    Queue::assertPushed(SendDiscordMessage::class, 2);
});

it('marks events Discord refused instead of sending them again', function () {
    $incident = siteIncident();
    $event = $incident->events()->sole();
    $event->forceFill(['notified_at' => now()])->save();

    (new SendDiscordMessage(['embeds' => [], 'allowed_mentions' => ['parse' => []]], [$event->id]))
        ->failed(new RuntimeException('Discord answered HTTP 400', 400));

    expect($event->fresh())->notified_at->not->toBeNull()->suppressed_reason->toBe('rejected');
});

it('queues a heartbeat on each watched queue', function () {
    Queue::fake();

    $this->artisan('sextant:heartbeat')->assertSuccessful();

    foreach (['checks', 'checks-slow', 'notifications'] as $queue) {
        Queue::assertPushedOn($queue, Heartbeat::class);
    }
});

it('never reports checks that could not run to Sentry', function () {
    expect(app(ExceptionHandler::class)->shouldReport(new CheckCouldNotRun('Resolver timed out')))->toBeFalse()
        ->and(app(ExceptionHandler::class)->shouldReport(new RuntimeException('Bug')))->toBeTrue();
});

it('lists each package below its minimum with its CVE link', function () {
    $repository = Repository::factory()->create(['owner' => 'media24', 'name' => 'svet24']);
    $this->manager->open(
        Monitor::factory()->on($repository, 'repository.packages')->create(),
        $repository,
        new CheckResult(Status::Warning, 'laravel/framework v12.9.1 < 12.13', ['below' => [
            ['software' => 'laravel/framework', 'branch' => '12', 'version' => 'v12.9.1', 'min_version' => '12.13', 'cve_url' => 'https://github.com/advisories/GHSA-1'],
        ]]),
        now(),
    );
    $this->travel(21)->seconds();

    flushNotifications();

    expect(sentMessages()[0]['embeds'][0]['title'])->toBe('🟠 media24/svet24 — Packages')
        ->and(sentMessages()[0]['embeds'][0]['description'])->toContain('• laravel/framework v12.9.1 < 12.13 ([CVE](https://github.com/advisories/GHSA-1))');
});
