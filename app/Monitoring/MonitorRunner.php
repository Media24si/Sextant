<?php

namespace App\Monitoring;

use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\Checker;
use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Checks\ResolvedSettings;
use App\Checks\UnknownChecker;
use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorRun;
use App\Models\MonitorState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The run pipeline (SPEC §5.3): run the checker, turn failures on our side
 * into Unknown, store the run, update state and the resource's status.
 */
class MonitorRunner
{
    /** Registry lookups per registry per minute (§5.2). */
    private const REGISTRY_RUNS_PER_MINUTE = 10;

    public function __construct(
        private CheckerRegistry $registry,
        private NetworkHealth $network,
        private StateMachine $stateMachine,
        private ChangeDetector $changes,
        private DependencyChecks $dependencies,
    ) {}

    /**
     * @param  bool  $save  false runs the checker and stores nothing (`monitors:run --no-save`)
     */
    public function run(int $monitorId, bool $save = true): ?RunOutcome
    {
        $monitor = Monitor::query()->find($monitorId);
        $resource = $monitor?->resource();

        if ($monitor === null || $resource === null || ! $monitor->enabled || $this->isTrashed($resource)) {
            return null;
        }

        if ($save && ! $this->registryAllows($monitor, $resource)) {
            return null;
        }

        [$checker, $settings, $result] = $this->resolve($monitor);

        $start = hrtime(true);

        if ($result === null && $checker !== null) {
            $result = $this->execute($checker, $resource, $settings->arguments);
        }

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
        $result ??= CheckResult::unknown('Checker error');

        if ($result->isFailure() && $settings->requiresNetwork && ! $this->network->isHealthy()) {
            $result = CheckResult::unknown("Discarded: Sextant's own network check failed");
        }

        $result = $result->cappedAt($settings->maxSeverity);

        if (! $save) {
            return new RunOutcome($result, $durationMs);
        }

        $transition = DB::transaction(function () use ($monitor, $resource, $result, $settings, $durationMs): StateTransition {
            $now = now();
            /** @var MonitorState $state */
            $state = MonitorState::query()->whereKey($monitor->id)->lockForUpdate()->first()
                ?? MonitorState::query()->create(['monitor_id' => $monitor->id, 'status' => Status::Unknown]);

            $trackedChanged = $result->isDefinitive() && $this->registry->has($monitor->checker)
                && $this->changes->detect($monitor, $resource, $this->registry->checkerClass($monitor->checker), $state->data, $result->data);

            MonitorRun::query()->create([
                'monitor_id' => $monitor->id,
                'status' => $result->status,
                'duration_ms' => $durationMs,
                'message' => $result->status === Status::Ok ? null : mb_strimwidth($result->message, 0, 500, '…'),
                'data' => $result->status !== Status::Ok || $trackedChanged ? $result->data : null,
                'checked_at' => $now,
            ]);

            $incident = Incident::query()->open()->where('monitor_id', $monitor->id)->lockForUpdate()->first();
            $transition = $this->stateMachine->apply($monitor, $resource, $state, $incident, $result, $settings, $durationMs, $now);
            $state->save();

            ResourceStatus::refresh($resource);

            return $transition;
        });

        if ($transition->firstFailure) {
            // A site's first failure checks its backend server right away (§6.5).
            $this->dependencies->checkBackendOf($resource);
        }

        if ($transition->recheckSoon) {
            // Confirm a failure a minute later instead of after the full interval (§5.1).
            Monitor::query()->whereKey($monitor->id)->where('next_run_at', '>', now()->addMinute())
                ->toBase()->update(['next_run_at' => now()->addMinute()->startOfSecond()]);
        }

        return new RunOutcome($result, $durationMs, $transition);
    }

    /**
     * @return array{Checker|null, ResolvedSettings, CheckResult|null}
     */
    private function resolve(Monitor $monitor): array
    {
        try {
            return [$this->registry->checker($monitor->checker), $this->registry->settings($monitor), null];
        } catch (UnknownChecker $exception) {
            if (Cache::add("unknown-checker:{$monitor->checker}", true, now()->addDay())) {
                report($exception);
            }

            $settings = new ResolvedSettings(
                arguments: new Arguments([], []),
                interval: $monitor->interval_minutes ?? 60,
                failuresBeforeIncident: $monitor->failures_before_incident ?? 2,
                successesBeforeResolve: $monitor->successes_before_resolve ?? 1,
                maxSeverity: Status::Error,
                requiresNetwork: false,
                queue: 'checks',
            );

            return [null, $settings, CheckResult::unknown($exception->getMessage())];
        }
    }

    private function execute(Checker $checker, Model $resource, Arguments $arguments): CheckResult
    {
        try {
            return $checker->run($resource, $arguments);
        } catch (CheckCouldNotRun $exception) {
            return CheckResult::unknown($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return CheckResult::unknown('Checker error: '.mb_strimwidth($exception->getMessage(), 0, 300, '…'));
        }
    }

    /**
     * Registry lookups are limited per registry (§5.2). Over the limit, the
     * run moves a minute or two later instead of being lost.
     *
     * SPEC: §5.2 names Laravel's `RateLimited` middleware, but with `$tries = 1`
     * a released job fails, so the limit is applied here instead. The TLD
     * stands in for the registry host.
     */
    private function registryAllows(Monitor $monitor, Model $resource): bool
    {
        if (! $resource instanceof Domain || ! $this->registry->has($monitor->checker) || $this->registry->queue($monitor) !== 'checks-slow') {
            return true;
        }

        $tld = substr((string) strrchr($resource->name, '.'), 1);

        if (RateLimiter::attempt("registry:{$tld}", self::REGISTRY_RUNS_PER_MINUTE, fn (): bool => true, 60)) {
            return true;
        }

        Monitor::query()->whereKey($monitor->id)->toBase()->update(['next_run_at' => now()->addSeconds(random_int(60, 180))]);

        return false;
    }

    private function isTrashed(Model&Monitorable $resource): bool
    {
        return method_exists($resource, 'trashed') && $resource->trashed();
    }
}
