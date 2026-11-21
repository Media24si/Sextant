<?php

use App\Enums\Software;
use App\Enums\Status;
use App\Jobs\RunMonitor;
use App\Models\Activity;
use App\Models\Incident;
use App\Models\MinimumVersion;
use App\Models\Monitor;
use App\Models\MonitorRun;
use App\Models\Server;
use App\Models\ServerSoftware;
use App\Models\User;
use App\Net\VersionsClient;
use App\Versions\VersionsFile;
use App\Versions\VersionsRecorder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

/*
 * Software versions (SPEC §11, §9.9): recorder, checker, token and
 * downloads, minimums, and what the pages show.
 */
const VERSIONS_URL = 'https://web02.media24.si/.sextant/versions.php';

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    $this->web02->forceFill(['versions_url' => VERSIONS_URL, 'versions_token' => 'sxt_'.str_repeat('a', 32), 'versions_token_last4' => 'aaaa'])->saveQuietly();
    $this->client = app(VersionsClient::class);
});

function versionsFile(array $changes = []): array
{
    $file = json_decode((string) file_get_contents(base_path('tests/Fixtures/versions/web02.json')), true);
    $file['generated_at'] = now()->subHour()->toIso8601String();

    return array_replace($file, $changes);
}

function versionsMonitor(): Monitor
{
    return Monitor::factory()->on(test()->web02, 'server.versions')->create();
}

function record(array $file): void
{
    app(VersionsRecorder::class)->record(test()->web02, VersionsFile::fromArray($file), now());
}

it('records nothing in the feed on the first reading, then every change', function () {
    record(versionsFile());

    expect(ServerSoftware::query()->count())->toBe(6)
        ->and(Activity::query()->where('category', 'detected')->count())->toBe(0)
        ->and($this->web02->fresh()->os_reported)->toBe('Ubuntu 24.04.1 LTS')
        ->and(Activity::query()->where('type', 'server.updated')->exists())->toBeFalse();

    record(versionsFile([
        'nginx' => ['version' => '1.24.0', 'package' => null],
        'openssl' => ['version' => '3.0.15', 'package' => null],
        'mysql' => ['version' => '8.0.40', 'package' => null],
        'php' => [['branch' => '8.3', 'version' => '8.3.14', 'cli' => true, 'fpm' => true]],
        'os' => ['id' => 'ubuntu', 'version_id' => '24.10', 'pretty_name' => 'Ubuntu 24.10'],
    ]));

    expect(Activity::query()->where('category', 'detected')->orderBy('id')->pluck('summary', 'type')->all())->toBe([
        'server.os_changed' => 'web02: OS Ubuntu 24.04.1 LTS → Ubuntu 24.10',
        'software.downgraded' => 'web02: nginx 1.26.2 → 1.24.0',
        'software.installed' => 'web02: MySQL 8.0.40 installed',
        'software.upgraded' => 'web02: OpenSSL 3.0.13 → 3.0.15',
        'software.removed' => 'web02: PHP 8.2 8.2.20 removed',
    ])->and(ServerSoftware::query()->where('software', 'php')->pluck('branch')->all())->toBe(['8.3']);
});

it('is OK when everything is at or above the minimum', function () {
    MinimumVersion::factory()->create(['software' => Software::Nginx, 'min_version' => '1.26.2']);
    $this->client->respond(VERSIONS_URL, versionsFile());
    $monitor = versionsMonitor();

    RunMonitor::dispatchSync($monitor->id);

    expect(MonitorRun::query()->latest('id')->first())->status->toBe(Status::Ok)
        ->and($monitor->state->fresh()->message)->toBe('6 readings, all at or above minimum')
        ->and($this->client->fetched[0]['token'])->toBe('sxt_'.str_repeat('a', 32));
});

it('warns about every reading below its minimum, PHP per branch', function () {
    MinimumVersion::factory()->create(['software' => Software::Php, 'branch' => '8.2', 'min_version' => '8.2.26', 'cve_url' => 'https://cve.example/2024-1']);
    MinimumVersion::factory()->create(['software' => Software::Php, 'branch' => '7.4', 'min_version' => '7.4.33']);
    MinimumVersion::factory()->create(['software' => Software::Nginx, 'min_version' => '1.27.0']);
    $this->client->respond(VERSIONS_URL, versionsFile());

    RunMonitor::dispatchSync(versionsMonitor()->id);

    $incident = Incident::query()->sole();

    expect($incident->severity)->toBe(Status::Warning)
        ->and($incident->message)->toBe('nginx 1.26.2 < 1.27.0; PHP 8.2 8.2.20 < 8.2.26')
        ->and($incident->last_result['data']['below'][1]['cve_url'])->toBe('https://cve.example/2024-1');
});

it('is Unknown and keeps the stored versions when the file can\'t be read', function (Closure $setUp, string $message) {
    record(versionsFile());
    $setUp($this);

    RunMonitor::dispatchSync(versionsMonitor()->id);

    expect(MonitorRun::query()->latest('id')->first())->status->toBe(Status::Unknown)->message->toBe($message)
        ->and(ServerSoftware::query()->count())->toBe(6);
})->with([
    'token rejected' => [fn ($test) => $test->client->fail(VERSIONS_URL, 'Token rejected (401)'), 'Token rejected (401)'],
    'timeout' => [fn ($test) => null, 'Endpoint timed out'],
    'stale file' => [fn ($test) => $test->client->respond(VERSIONS_URL, versionsFile(['generated_at' => now()->subDays(3)->toIso8601String()])), 'Collector last ran 3 d ago'],
    'invalid file' => [fn ($test) => $test->client->respond(VERSIONS_URL, versionsFile(['schema' => 2])), 'Invalid versions file: unsupported schema 2'],
]);

it('re-runs every versions check when a minimum is set, changed or removed', function () {
    Queue::fake();
    $monitor = versionsMonitor();
    $monitor->forceFill(['next_run_at' => now()->addDay()])->saveQuietly();

    $this->actingAs($this->admin)->post(route('minimum-versions.store'), ['software' => 'nginx', 'min_version' => '1.26.2'])->assertSessionHasNoErrors();
    $minimum = MinimumVersion::query()->sole();
    $this->put(route('minimum-versions.update', $minimum), ['software' => 'nginx', 'min_version' => '1.27.0'])->assertSessionHasNoErrors();
    $this->delete(route('minimum-versions.destroy', $minimum));

    Queue::assertPushed(RunMonitor::class, 3);
    expect(Activity::query()->where('type', 'like', 'minimum_version.%')->orderBy('id')->pluck('summary')->all())->toBe([
        'Minimum nginx set to 1.26.2',
        'Minimum nginx changed to 1.27.0 (was 1.26.2)',
        'Minimum nginx removed (was 1.27.0)',
    ]);
});

it('validates minimums', function (array $input, string $error) {
    MinimumVersion::factory()->create(['software' => Software::Php, 'branch' => '8.2']);

    $this->actingAs($this->admin)->post(route('minimum-versions.store'), $input)->assertSessionHasErrors($error);
})->with([
    'os has no minimum' => [['software' => 'os', 'min_version' => '24.04'], 'software'],
    'php needs a branch' => [['software' => 'php', 'min_version' => '8.3.14'], 'branch'],
    'one per branch' => [['software' => 'php', 'branch' => '8.2', 'min_version' => '8.2.30'], 'branch'],
    'not a version' => [['software' => 'nginx', 'min_version' => 'latest'], 'min_version'],
]);

it('shows a token once and keeps only its last four characters', function () {
    $response = $this->actingAs($this->admin)->postJson(route('servers.versions-token', $this->web02))->assertOk();
    $token = $response->json('token');

    expect($token)->toMatch('/^sxt_[0-9a-f]{32}$/')
        ->and($this->web02->fresh()->versions_token)->toBe($token)
        ->and($this->web02->fresh()->versions_token_last4)->toBe(substr($token, -4))
        ->and(Activity::query()->where('type', 'server.versions_token_rotated')->sole()->properties)->toBeNull();

    $this->get(route('servers.edit', $this->web02))
        ->assertInertia(fn (Assert $page) => $page->where('form.versions_token_masked', 'sxt_••••'.substr($token, -4)))
        ->assertDontSee($token);
});

it('attaches the versions monitor once the endpoint and a token are set', function () {
    Queue::fake();
    $server = Server::factory()->create();

    $this->actingAs($this->admin)->postJson(route('servers.versions-token', $server));
    expect($server->monitors()->count())->toBe(0);

    $this->put(route('servers.update', $server), [
        'name' => $server->name,
        'versions_url' => 'https://example.media24.si/.sextant/versions.php',
        'addresses' => [['ip' => '192.0.2.50', 'is_primary' => true]],
    ])->assertSessionHasNoErrors();

    expect($server->monitors()->pluck('checker')->all())->toBe(['server.versions']);
});

it('serves the collector unchanged and versions.php with the hash, never the token', function () {
    $this->actingAs($this->admin);

    $collector = $this->get(route('servers.collector', $this->web02))->assertOk()->assertDownload('sextant-collect.sh');
    expect($collector->getContent())->toBe(file_get_contents(resource_path('collector/sextant-collect.sh')));

    $php = $this->get(route('servers.versions-php', $this->web02))->assertOk()->assertDownload('versions.php')->getContent();

    expect($php)->toContain(hash('sha256', 'sxt_'.str_repeat('a', 32)))
        ->and($php)->not->toContain('sxt_'.str_repeat('a', 32))
        ->and($php)->toContain("'/var/lib/sextant/versions.json'");
});

it('runs the rendered versions.php: 401 without or with a wrong token, 405 on POST, 200 with the token', function () {
    $dir = sys_get_temp_dir().'/sextant-php-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/versions.json", json_encode(versionsFile()));
    config(['sextant.versions.file_path' => "{$dir}/versions.json"]);
    $php = $this->actingAs($this->admin)->get(route('servers.versions-php', $this->web02))->getContent();
    file_put_contents("{$dir}/versions.php", $php);
    // php -S doesn't speak HTTPS; the router marks requests as HTTPS like nginx would.
    file_put_contents("{$dir}/router.php", "<?php \$_SERVER['HTTPS'] = 'on'; require __DIR__.'/versions.php';");

    $port = random_int(20000, 40000);
    $process = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$dir}/router.php"]);
    $process->start();
    usleep(400_000);

    try {
        Illuminate\Support\Facades\Http::allowStrayRequests();
        $url = "http://127.0.0.1:{$port}/";

        expect(Http::get($url)->status())->toBe(401)
            ->and(Http::withToken('sxt_wrong')->get($url)->status())->toBe(401)
            ->and(Http::withToken('sxt_'.str_repeat('a', 32))->post($url)->status())->toBe(405);

        $ok = Http::withToken('sxt_'.str_repeat('a', 32))->get($url);

        expect($ok->status())->toBe(200)
            ->and($ok->header('Cache-Control'))->toContain('no-store')
            ->and($ok->json('hostname'))->toBe('web02');
    } finally {
        $process->stop();
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }
});

it('tests the connection without storing anything', function () {
    $this->client->respond(VERSIONS_URL, versionsFile());

    $this->actingAs($this->admin)->postJson(route('servers.versions.test', $this->web02))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('readings.0.software', 'Operating system')
        ->assertJsonCount(6, 'readings');

    expect(ServerSoftware::query()->count())->toBe(0);

    $this->client->fail(VERSIONS_URL, 'Token rejected (401)');
    $this->postJson(route('servers.versions.test', $this->web02))->assertJson(['ok' => false, 'error' => 'Token rejected (401)']);
});

it('shows versions on the servers list, filters servers below a minimum, and finds them by version', function () {
    record(versionsFile());
    Server::factory()->create(['name' => 'web09']);
    MinimumVersion::factory()->create(['software' => Software::Php, 'branch' => '8.2', 'min_version' => '8.2.26']);

    $this->actingAs(User::factory()->reader()->create())->get(route('servers.index', ['filter' => ['show' => 'below_minimum']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.below_minimum', 1)
            ->has('rows.data', 1)
            ->where('rows.data.0.versions_configured', true)
            ->where('rows.data.0.versions', fn ($versions) => collect($versions)->firstWhere('label', 'PHP 8.2')['below_minimum'] === true));

    $this->getJson(route('search', ['q' => '8.2.20']))
        ->assertJsonPath('results.0.label', 'web02')
        ->assertJsonPath('results.0.context', fn (string $context) => str_contains($context, 'PHP 8.2 8.2.20'));
});

it('puts software against minimums on the dashboard and the server page', function () {
    record(versionsFile());
    MinimumVersion::factory()->create(['software' => Software::Nginx, 'min_version' => '1.27.0']);

    $this->actingAs(User::factory()->reader()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.servers_below_minimum', 1)
            ->where('software.1', ['software' => 'nginx', 'branch' => '', 'min_version' => '1.27.0', 'below' => [['server' => 'web02', 'version' => '1.26.2']]]));

    $this->get(route('servers.show', $this->web02))
        ->assertInertia(fn (Assert $page) => $page
            ->where('versions.configured', true)
            ->where('versions.token_masked', 'sxt_••••aaaa')
            ->where('versions.os_reported', 'Ubuntu 24.04.1 LTS')
            ->has('versions.versions', 6));
});
