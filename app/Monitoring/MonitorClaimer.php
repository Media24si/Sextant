<?php

namespace App\Monitoring;

use App\Checks\CheckerRegistry;
use App\Checks\UnknownChecker;
use App\Jobs\RunMonitor;
use App\Models\Monitor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Claims monitor runs with compare-and-set on `next_run_at` and queues them
 * (SPEC §5.1). `next_run_at` moves forward at claim time, so a queued or slow
 * run is never dispatched twice. Dispatch, "Run now" and dependency checks
 * all come through here.
 */
class MonitorClaimer
{
    private const BATCH = 200;

    /** Unregistered checkers still get claimed, hourly, and run to Unknown (§5.3). */
    private const UNKNOWN_CHECKER_INTERVAL = 60;

    public const LAST_DISPATCH_KEY = 'monitors:last-dispatch-at';

    public function __construct(private CheckerRegistry $registry) {}

    /**
     * Claim due monitors in batches until none are due or the deadline passes.
     *
     * @return int runs queued
     */
    public function dispatchDue(CarbonInterface $deadline): int
    {
        $queued = 0;

        do {
            $due = $this->dueQuery()->limit(self::BATCH)->get(['id', 'next_run_at', 'checker', 'interval_minutes']);

            foreach ($due as $monitor) {
                $queued += $this->claim($monitor) ? 1 : 0;
            }
        } while ($due->count() === self::BATCH && now()->lt($deadline));

        Cache::forever(self::LAST_DISPATCH_KEY, now()->toIso8601String());

        return $queued;
    }

    /**
     * @return Builder<Monitor>
     */
    public function dueQuery(): Builder
    {
        return Monitor::query()
            ->where('enabled', true)
            ->where('next_run_at', '<=', now())
            ->whereHasMorph('monitorable', '*')
            ->orderBy('next_run_at');
    }

    /**
     * Compare-and-set: only one caller can claim a given run.
     */
    public function claim(Monitor $monitor): bool
    {
        $claimed = Monitor::query()
            ->whereKey($monitor->id)
            ->where('next_run_at', $monitor->next_run_at)
            ->toBase()
            ->update(['next_run_at' => now()->addMinutes($this->interval($monitor))]);

        if ($claimed !== 1) {
            return false;
        }

        // After commit: a claim made inside a transaction (saving a server, say)
        // must not reach a worker before the monitor it runs exists.
        RunMonitor::dispatch($monitor->id)->onQueue($this->queue($monitor))->afterCommit();

        return true;
    }

    /**
     * "Run now" (§5.1, §6.4): make it due, then claim it like the dispatcher would.
     */
    public function claimNow(Monitor $monitor): bool
    {
        if (! $monitor->enabled) {
            return false;
        }

        $now = now()->startOfSecond();
        Monitor::query()->whereKey($monitor->id)->toBase()->update(['next_run_at' => $now]);
        $monitor->next_run_at = $now;

        return $this->claim($monitor);
    }

    private function interval(Monitor $monitor): int
    {
        try {
            return $this->registry->interval($monitor);
        } catch (UnknownChecker) {
            return $monitor->interval_minutes ?? self::UNKNOWN_CHECKER_INTERVAL;
        }
    }

    private function queue(Monitor $monitor): string
    {
        try {
            return $this->registry->queue($monitor);
        } catch (UnknownChecker) {
            return 'checks';
        }
    }
}
