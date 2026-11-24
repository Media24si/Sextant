<?php

use App\Enums\Status;
use App\Jobs\RunMonitor;
use App\Models\Activity;
use App\Models\Application;
use App\Models\GithubToken;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorRun;
use App\Models\PackageMinimum;
use App\Models\Repository;
use App\Models\RepositoryPackage;
use App\Models\Site;
use App\Models\User;
use App\Net\GithubClient;
use App\Net\Results\GithubRepository;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Repositories and their packages (SPEC §3.21–3.23, §9.10, §11.9): one
 * repository per GitHub link, lock files read with the owner's token, and
 * packages compared with the minimums per major.
 */
const SVET24 = 'https://github.com/media24/svet24';
const SVET24_TOKEN = 'github_pat_11SVET24TOKEN_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRS';

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->github = app(GithubClient::class);
});

/**
 * @param  array<int|string, string>  $names  fixture names, or published name => fixture name
 */
function fixtureFiles(array $names): array
{
    $files = [];

    foreach ($names as $published => $fixture) {
        $files[is_string($published) ? $published : $fixture] = (string) file_get_contents(base_path("tests/Fixtures/lockfiles/{$fixture}"));
    }

    return $files;
}

/** svet24 linked from an application, with a token for media24 and its lock files on GitHub. */
function svet24(array $files = [], ?GithubRepository $github = null): Repository
{
    GithubToken::factory()->create(['owner' => 'media24', 'token' => SVET24_TOKEN]);
    test()->github->repo('media24/svet24', $files, SVET24_TOKEN, $github);
    Application::factory()->create(['name' => 'Svet24', 'github_url' => SVET24]);

    return Repository::query()->sole();
}

function packagesMonitor(Repository $repository): Monitor
{
    return $repository->monitors()->where('checker', 'repository.packages')->sole();
}

it('creates one repository per link with its packages monitor, and reads it at once', function () {
    $application = Application::factory()->create(['github_url' => SVET24]);
    Site::factory()->create(['github_url' => 'https://github.com/Media24/Svet24']);
    Site::factory()->create(['application_id' => $application->id]);

    $repository = Repository::query()->sole();

    expect($repository->only(['owner', 'name', 'url']))->toBe(['owner' => 'media24', 'name' => 'svet24', 'url' => SVET24])
        ->and(packagesMonitor($repository)->state->message)->toBe('No GitHub token for media24: add one under Settings → GitHub access')
        ->and(packagesMonitor($repository)->state->status)->toBe(Status::Unknown)
        ->and(Activity::query()->where('subject_type', 'repository')->count())->toBe(0);
});

it('removes a repository nothing links any more, resolving its incidents, and restores it when a link comes back', function () {
    PackageMinimum::factory()->create(['name' => 'laravel/framework', 'min_versions' => ['12.13']]);
    $repository = svet24(fixtureFiles(['composer.lock', 'composer.json']));
    RunMonitor::dispatchSync(packagesMonitor($repository)->id);
    expect(Incident::query()->open()->count())->toBe(1);

    Application::query()->sole()->update(['github_url' => null]);

    expect($repository->fresh()->trashed())->toBeTrue()
        ->and(Incident::query()->open()->count())->toBe(0);

    Site::factory()->create(['github_url' => SVET24]);

    expect($repository->fresh()->trashed())->toBeFalse()
        ->and(Repository::withTrashed()->count())->toBe(1);
});

it('reads every lock file in the root and stores the packages', function () {
    $repository = svet24(
        fixtureFiles(['composer.lock', 'composer.json', 'package-lock.json' => 'package-lock-v3.json', 'README.md' => 'package.json']),
        new GithubRepository('production', false),
    );

    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    $repository->refresh();

    expect(packagesMonitor($repository)->state->status)->toBe(Status::Ok)
        ->and(packagesMonitor($repository)->state->message)->toBe('12 packages from composer.lock, package-lock.json, all at or above minimum')
        ->and($repository->default_branch)->toBe('production')
        ->and($repository->lock_files)->toBe(['composer.lock', 'package-lock.json'])
        ->and($repository->read_at)->not->toBeNull()
        ->and(RepositoryPackage::query()->where('name', 'laravel/framework')->sole()->only(['version', 'direct', 'dev']))
        ->toBe(['version' => 'v12.9.1', 'direct' => true, 'dev' => false])
        ->and(collect($this->github->requests)->pluck('token')->unique()->all())->toBe([SVET24_TOKEN]);
});

it('replaces the packages on every read', function () {
    $repository = svet24(fixtureFiles(['composer.lock', 'composer.json']));
    RunMonitor::dispatchSync(packagesMonitor($repository)->id);
    $this->github->repo('media24/svet24', fixtureFiles(['pnpm-lock.yaml' => 'pnpm-lock-v9.yaml']), SVET24_TOKEN);

    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    expect(RepositoryPackage::query()->pluck('name')->sort()->values()->all())
        ->toBe(['@inertiajs/vue3', 'axios', 'typescript', 'vite', 'vue']);
});

it('warns about every package below its minimum, with the CVE link', function () {
    PackageMinimum::factory()->create(['name' => 'laravel/framework', 'min_versions' => ['12.13', '13.39'], 'cve_url' => 'https://github.com/advisories/GHSA-1']);
    PackageMinimum::factory()->create(['ecosystem' => 'npm', 'name' => 'axios', 'min_versions' => ['1.8.2']]);
    PackageMinimum::factory()->create(['ecosystem' => 'npm', 'name' => 'vue', 'min_versions' => ['3.5.0']]);
    $repository = svet24(fixtureFiles(['composer.lock', 'composer.json', 'package-lock.json' => 'package-lock-v3.json']));

    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    $incident = Incident::query()->sole();

    expect($incident->severity)->toBe(Status::Warning)
        ->and($incident->message)->toBe('laravel/framework v12.9.1 < 12.13; axios 1.7.9 < 1.8.2')
        ->and($incident->last_result['data']['below'][0])->toEqual([
            'software' => 'laravel/framework',
            'branch' => '12',
            'version' => 'v12.9.1',
            'min_version' => '12.13',
            'cve_url' => 'https://github.com/advisories/GHSA-1',
        ]);
});

it('checks packages pulled in by other packages, and the site lists only those with a minimum', function () {
    PackageMinimum::factory()->create(['name' => 'guzzlehttp/guzzle', 'min_versions' => ['7.9.3']]);
    $repository = svet24(fixtureFiles(['composer.lock', 'composer.json']));
    $site = Site::factory()->create(['application_id' => Application::query()->sole()->id]);

    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    expect(Incident::query()->sole()->message)->toBe('guzzlehttp/guzzle 7.9.2 < 7.9.3');

    $this->actingAs($this->admin)->get(route('sites.show', $site))
        ->assertInertia(fn (Assert $page) => $page
            ->where('repository.packages_count', 5)
            ->has('repository.watched', 1)
            ->where('repository.watched.0.name', 'guzzlehttp/guzzle')
            ->where('repository.watched.0.direct', false)
            ->where('repository.watched.0.below_minimum', true));

    $this->get(route('minimum-versions.index'))
        ->assertInertia(fn (Assert $page) => $page->where('package_names', fn ($names) => collect($names)->contains('guzzlehttp/guzzle')));
});

it('is Unknown and keeps the last packages when it can\'t read them', function (Closure $setUp, string $message) {
    $repository = svet24(fixtureFiles(['composer.lock', 'composer.json']));
    RunMonitor::dispatchSync(packagesMonitor($repository)->id);
    $setUp($this);

    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    expect(MonitorRun::query()->latest('id')->first())->status->toBe(Status::Unknown)->message->toBe($message)
        ->and(RepositoryPackage::query()->count())->toBe(5);
})->with([
    'token removed' => [fn () => GithubToken::query()->delete(), 'No GitHub token for media24: add one under Settings → GitHub access'],
    'token revoked' => [fn ($test) => $test->github->fail('media24/svet24', 'GitHub token rejected (401): expired or revoked'), 'GitHub token rejected (401): expired or revoked'],
    'broken lock file' => [fn ($test) => $test->github->repo('media24/svet24', ['composer.lock' => '{"packages": ['], SVET24_TOKEN), 'composer.lock is not valid JSON'],
]);

it('says so when there is no lock file', function () {
    $repository = svet24(['README.md' => '# Svet24']);

    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    expect(packagesMonitor($repository)->state->message)->toBe('No lock file in the root of main')
        ->and(packagesMonitor($repository)->state->status)->toBe(Status::Ok);
});

it('warns before the owner\'s token expires and remembers when', function () {
    $expires = now()->addDays(5)->startOfSecond()->toImmutable();
    $repository = svet24(fixtureFiles(['composer.lock']), new GithubRepository('main', false, $expires));

    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    expect(packagesMonitor($repository)->state->status)->toBe(Status::Warning)
        ->and(packagesMonitor($repository)->state->message)->toBe('GitHub token for media24 expires in 5 d')
        ->and(GithubToken::query()->sole()->expires_at->equalTo($expires))->toBeTrue();
});

it('re-reads every repository when a package minimum is set, changed or removed', function () {
    $repository = Repository::factory()->create();
    $monitor = Monitor::factory()->on($repository, 'repository.packages')->create(['next_run_at' => now()->addDay()]);
    Queue::fake();

    $this->actingAs($this->admin)->post(route('package-minimums.store'), ['ecosystem' => 'composer', 'name' => ' Laravel/Framework ', 'min_versions' => 'v13.39, 12.13'])
        ->assertSessionHasNoErrors();
    $minimum = PackageMinimum::query()->sole();
    $this->put(route('package-minimums.update', $minimum), ['ecosystem' => 'composer', 'name' => 'laravel/framework', 'min_versions' => '12.14, 13.40', 'cve_url' => 'https://github.com/advisories/GHSA-2'])
        ->assertSessionHasNoErrors();
    $this->delete(route('package-minimums.destroy', $minimum));

    Queue::assertPushed(RunMonitor::class, 3);
    expect($monitor->fresh()->next_run_at->isFuture())->toBeTrue()
        ->and(Activity::query()->where('type', 'like', 'package_minimum.%')->orderBy('id')->pluck('summary')->all())->toBe([
            'Minimum laravel/framework (Composer) set to 12.13, 13.39',
            'Minimum laravel/framework (Composer) changed to 12.14, 13.40 (was 12.13, 13.39)',
            'Minimum laravel/framework (Composer) removed (was 12.14, 13.40)',
        ]);
});

it('accepts a version per minor line', function () {
    $this->actingAs($this->admin)->post(route('package-minimums.store'), ['ecosystem' => 'npm', 'name' => 'vite', 'min_versions' => '8.3.7, 8.2.5, 8.1.7, 7.3.8, 6.4.6'])
        ->assertSessionHasNoErrors();

    expect(PackageMinimum::query()->sole()->min_versions)->toBe(['6.4.6', '7.3.8', '8.1.7', '8.2.5', '8.3.7']);
});

it('validates package minimums', function (array $input, string $field, string $message) {
    PackageMinimum::factory()->create(['name' => 'laravel/framework']);

    $this->actingAs($this->admin)->post(route('package-minimums.store'), $input)->assertSessionHasErrors([$field => $message]);
})->with([
    'one per package' => [['ecosystem' => 'composer', 'name' => 'laravel/framework', 'min_versions' => '13.40'], 'name', 'This package already has a minimum; change that one instead.'],
    'one per line' => [['ecosystem' => 'npm', 'name' => 'axios', 'min_versions' => '1.8.5, 1.8.2'], 'min_versions', 'One version per line: 1.8.2 and 1.8.5 are both 1.8.x.'],
    'a major-wide version covers its lines' => [['ecosystem' => 'npm', 'name' => 'axios', 'min_versions' => '1.13, 1.8.2'], 'min_versions', "1.13 covers every 1.x, so 1.8.2 can't be listed with it."],
    'composer names have a vendor' => [['ecosystem' => 'composer', 'name' => 'framework', 'min_versions' => '1.0'], 'name', 'Enter the package as vendor/name, e.g. laravel/framework.'],
    'not a version' => [['ecosystem' => 'npm', 'name' => '@inertiajs/vue3', 'min_versions' => 'latest'], 'min_versions.0', 'Enter versions such as 12.13 or 13.39.1, separated by commas.'],
]);

it('shows the packages, who uses the repository and what is below', function () {
    PackageMinimum::factory()->create(['name' => 'laravel/framework', 'min_versions' => ['12.13']]);
    $repository = svet24(fixtureFiles(['composer.lock', 'composer.json']));
    $site = Site::factory()->create(['application_id' => Application::query()->sole()->id]);
    RunMonitor::dispatchSync(packagesMonitor($repository)->id);

    $this->actingAs(User::factory()->reader()->create())->get(route('repositories.show', $repository))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('repositories/Show')
            ->where('repository.full_name', 'media24/svet24')
            ->where('repository.below_count', 1)
            ->where('repository.has_token', true)
            ->where('repository.used_by.0', ['type' => 'application', 'id' => Application::query()->sole()->id, 'name' => 'Svet24', 'via' => null])
            ->where('repository.used_by.1.via', 'Svet24')
            ->has('packages', 5)
            ->where('packages.1.name', 'laravel/framework')
            ->where('packages.1.below_minimum', true)
            ->where('packages.1.min_version', '12.13'))
        ->assertDontSee(SVET24_TOKEN);

    $this->get(route('sites.show', $site))
        ->assertInertia(fn (Assert $page) => $page
            ->where('repository.full_name', 'media24/svet24')
            ->where('repository.via', 'Svet24')
            ->where('repository.packages_count', 5)
            ->has('repository.watched', 1)
            ->where('repository.watched.0.version', 'v12.9.1'));

    $this->get(route('minimum-versions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('package_minimums.0.name', 'laravel/framework')
            ->where('package_minimums.0.repositories_below', [['id' => $repository->id, 'name' => 'media24/svet24', 'version' => 'v12.9.1']]));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('packages.0.repositories_below.0.name', 'media24/svet24'));

    $this->get(route('repositories.index'))
        ->assertInertia(fn (Assert $page) => $page->has('repositories', 1)->where('repositories.0.packages_count', 5));
});
