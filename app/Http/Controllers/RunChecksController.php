<?php

namespace App\Http\Controllers;

use App\Models\Concerns\Monitorable;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\MonitorClaimer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * "Run checks now" on a resource page (SPEC §5.1, §13.3): every enabled
 * monitor is claimed for an immediate run.
 *
 * SPEC: §13.3 lists `POST /servers/{server}/run-checks`; §13.6 also gives the
 * site page a "run checks" action, and domains get the same for symmetry.
 */
class RunChecksController extends Controller
{
    public function __construct(private MonitorClaimer $claimer) {}

    public function server(Server $server): RedirectResponse
    {
        return $this->runAll($server);
    }

    public function domain(Domain $domain): RedirectResponse
    {
        return $this->runAll($domain);
    }

    public function site(Site $site): RedirectResponse
    {
        return $this->runAll($site);
    }

    /** "Read now" on a repository page (SPEC §11.9). */
    public function repository(Repository $repository): RedirectResponse
    {
        return $this->runAll($repository);
    }

    private function runAll(Model&Monitorable $resource): RedirectResponse
    {
        $queued = $resource->monitors()->where('enabled', true)->get()
            ->filter(fn (Monitor $monitor): bool => $this->claimer->claimNow($monitor))
            ->count();

        Inertia::flash('toast', $queued === 0
            ? ['type' => 'error', 'message' => "{$resource->resourceName()} has no enabled monitors."]
            : ['type' => 'success', 'message' => trans_choice('{1} 1 check is running.|[2,*] :count checks are running.', $queued).' Results show here in a few seconds.']);

        return back();
    }
}
