<?php

use App\Activity\ActivityRecorder;
use App\Enums\ActivityCategory;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->ana = User::factory()->admin()->create(['name' => 'Ana Novak']);
    $this->reader = User::factory()->reader()->create();
    Activity::query()->delete();
    $this->actingAs($this->ana);

    $this->web02 = Server::factory()->create(['name' => 'web02']);
    $domain = Domain::factory()->create(['name' => 'svet24.si']);
    $this->site = Site::factory()->for($domain)->create(['hostname' => 'svet24.si', 'server_id' => $this->web02->id]);

    auth()->logout();
    ActivityRecorder::record(ActivityCategory::Detected, 'certificate.renewed', $this->site, 'svet24.si: certificate renewed');
});

/**
 * @return list<string>
 */
function activityFor(array $filter): array
{
    $page = test()->actingAs(test()->reader)
        ->get(route('activity.index', ['filter' => $filter]))
        ->viewData('page');

    return collect($page['props']['rows']['data'])->pluck('type')->all();
}

test('readers see the whole feed, newest first, with links', function () {
    $this->actingAs($this->reader)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity/Index')
            ->where('rows.data.0.type', 'certificate.renewed')
            ->where('rows.data.0.user', null)
            ->where('rows.data.0.url', route('sites.show', $this->site, false))
            ->where('rows.data.1.type', 'site.created')
            ->where('rows.data.1.user', 'Ana Novak')
            ->where('categories', ['status', 'inventory', 'detected'])
            ->where('users.0.label', 'Ana Novak'));
});

test('the feed filters by category, type, resource, server and user', function () {
    expect(activityFor(['category' => 'detected']))->toBe(['certificate.renewed'])
        ->and(activityFor(['type' => 'domain.created']))->toBe(['domain.created'])
        ->and(activityFor(['resource' => 'server']))->toBe(['server.created'])
        ->and(activityFor(['server' => (string) $this->web02->id]))->toBe(['certificate.renewed', 'site.created', 'server.created'])
        ->and(activityFor(['user' => 'sextant']))->toBe(['certificate.renewed'])
        ->and(activityFor(['user' => (string) $this->ana->id]))->toBe(['site.created', 'domain.created', 'server.created']);
});

test('unknown filters are refused', function () {
    $this->actingAs($this->reader)
        ->get(route('activity.index', ['filter' => ['summary' => 'x']]))
        ->assertBadRequest();

    expect(Activity::query()->count())->toBeGreaterThan(0);
});
