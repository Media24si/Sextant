<?php

namespace App\Monitoring;

use App\Checks\CheckerRegistry;
use App\Checks\Conditions\Condition;
use App\Data\Detection\ProposedMonitorData;
use App\Models\Concerns\Monitorable;
use App\Models\Monitor;
use App\Models\MonitorState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Attaches the registry's default monitors to a resource (SPEC §4.4): each
 * `only_if` condition is evaluated, the entry's arguments are stored as
 * overrides, and the first run is due now (or spread over the first interval
 * for imports, §5.1). Written quietly: the resource's own `created` entry is
 * the change.
 */
class DefaultMonitors
{
    /** Imports spread first runs over at most this many minutes (§5.1). */
    private const MAX_SPREAD_MINUTES = 10;

    public function __construct(
        private CheckerRegistry $registry,
        private MonitorClaimer $claimer,
    ) {}

    /**
     * @param  list<ProposedMonitorData>  $proposed  detection's arguments, merged per checker and label
     * @param  string|null  $onlyChecker  `monitors:sync-defaults --checker`
     * @param  list<string>  $skipCheckers  checkers unticked on Add site
     * @return list<Monitor> the monitors created (or that would be, with $dryRun)
     */
    public function attach(Model&Monitorable $resource, array $proposed = [], bool $spread = false, ?string $onlyChecker = null, bool $dryRun = false, array $skipCheckers = []): array
    {
        $type = $resource->getMorphClass();

        if (! in_array($type, ['server', 'domain', 'site', 'repository'], true)) {
            return [];
        }
        $created = [];

        foreach ($this->registry->defaults($type) as $default) {
            $checker = $default['checker'];
            $label = $default['label'] ?? null;

            if (($onlyChecker !== null && $checker !== $onlyChecker) || in_array($checker, $skipCheckers, true)) {
                continue;
            }

            if ($resource->monitors()->where('checker', $checker)->where('label', $label)->exists()) {
                continue;
            }

            $arguments = [...($default['arguments'] ?? []), ...$this->proposedArguments($proposed, $checker, $label)];
            $condition = $default['only_if'] ?? null;

            /** @var Condition|null $check */
            $check = $condition === null ? null : app($condition);

            if ($check !== null && ! $check($resource, $arguments)) {
                continue;
            }

            $monitor = new Monitor([
                'checker' => $checker,
                'label' => $label,
                'arguments' => $arguments === [] ? null : $arguments,
                'enabled' => true,
                'next_run_at' => $this->firstRun($checker, $spread),
            ]);
            $monitor->monitorable()->associate($resource);

            if (! $dryRun) {
                $monitor->saveQuietly();
                MonitorState::query()->insertOrIgnore(['monitor_id' => $monitor->id, 'status' => 'unknown']);

                if (! $spread) {
                    $this->claimer->claim($monitor);
                }
            }

            $created[] = $monitor;
        }

        if (! $dryRun && $created !== []) {
            ResourceStatus::refresh($resource);
        }

        return $created;
    }

    /**
     * @param  list<ProposedMonitorData>  $proposed
     * @return array<string, mixed>
     */
    private function proposedArguments(array $proposed, string $checker, ?string $label): array
    {
        foreach ($proposed as $monitor) {
            if ($monitor->checker === $checker && $monitor->label === $label) {
                return $monitor->arguments;
            }
        }

        return [];
    }

    private function firstRun(string $checker, bool $spread): CarbonInterface
    {
        $now = now()->startOfSecond();

        if (! $spread) {
            return $now;
        }

        $window = min(self::MAX_SPREAD_MINUTES, $this->registry->entry($checker)['interval']) * 60;

        return $now->addSeconds(random_int(0, max(0, $window - 1)));
    }
}
