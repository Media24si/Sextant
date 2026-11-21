<?php

namespace App\Http\Controllers;

use App\Activity\ActivityRecorder;
use App\Enums\ActivityCategory;
use App\Models\Concerns\Monitorable;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\ResourceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Mute until…" (SPEC §6.6). A muted monitor still runs and opens incidents
 * but sends no notifications and is left out of the resource's status. On a
 * server it also mutes every site whose backend is that server, for
 * maintenance. An empty `until` unmutes.
 */
class MuteController extends Controller
{
    /** The longest mute; maintenance shouldn't silence a monitor for good. */
    private const MAX_DAYS = 30;

    public function monitor(Request $request, Monitor $monitor): RedirectResponse
    {
        $until = $this->until($request);

        $monitor->update(['muted_until' => $until]);

        $resource = $monitor->resource();

        if ($resource !== null) {
            ResourceStatus::refresh($resource);
        }

        return $this->done($until, 'the monitor');
    }

    public function server(Request $request, Server $server): RedirectResponse
    {
        $sites = Site::query()->where('server_id', $server->id)->get();

        return $this->muteResources($this->until($request), $server, array_values($sites->all()));
    }

    public function domain(Request $request, Domain $domain): RedirectResponse
    {
        return $this->muteResources($this->until($request), $domain);
    }

    public function site(Request $request, Site $site): RedirectResponse
    {
        return $this->muteResources($this->until($request), $site);
    }

    /**
     * @param  list<Site>  $alsoSites  a server's sites
     */
    private function muteResources(?CarbonImmutable $until, Model&Monitorable $resource, array $alsoSites = []): RedirectResponse
    {
        foreach ([$resource, ...$alsoSites] as $target) {
            // One feed entry for the whole action below, not one per monitor.
            $target->monitors()->toBase()->update(['muted_until' => $until]);
            ResourceStatus::refresh($target);
        }

        $sites = count($alsoSites) > 0 ? ' and '.trans_choice('{1} 1 site|[2,*] :count sites', count($alsoSites)) : '';
        $what = $until === null ? 'unmuted' : 'muted until '.$until->setTimezone((string) config('sextant.display_timezone'))->format('j M H:i');

        ActivityRecorder::record(
            ActivityCategory::Inventory,
            $until === null ? 'monitor.unmuted' : 'monitor.muted',
            $resource,
            "{$resource->resourceName()}{$sites}: all monitors {$what}",
            ['muted_until' => ['old' => null, 'new' => $until?->toIso8601String()], 'sites' => count($alsoSites)],
        );

        return $this->done($until, $resource->resourceName().$sites);
    }

    private function until(Request $request): ?CarbonImmutable
    {
        $request->validate([
            'until' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays(self::MAX_DAYS)->toIso8601String()],
        ], ['until.before_or_equal' => 'Mute for at most '.self::MAX_DAYS.' days.']);

        return $request->filled('until') ? CarbonImmutable::parse((string) $request->input('until'))->utc() : null;
    }

    private function done(?CarbonImmutable $until, string $what): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $until === null
            ? ucfirst("{$what} unmuted.")
            : ucfirst("{$what} muted until ".$until->setTimezone((string) config('sextant.display_timezone'))->format('j M H:i').'.')]);

        return back();
    }
}
