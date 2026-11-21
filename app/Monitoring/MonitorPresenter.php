<?php

namespace App\Monitoring;

use App\Checks\CheckerRegistry;
use App\Checks\UnknownChecker;
use App\Data\Monitors\ArgumentSchemaData;
use App\Data\Monitors\CheckerOptionData;
use App\Data\Monitors\MonitorDetailData;
use App\Data\Monitors\MonitorFormData;
use App\Data\Monitors\MonitorResourceData;
use App\Data\Monitors\MonitorRowData;
use App\Data\Monitors\MonitorRunData;
use App\Data\Monitors\MonitorSettingData;
use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use App\Models\Monitor;
use App\Models\MonitorRun;
use Illuminate\Database\Eloquent\Model;

/** Turns monitors into the data the Monitors UI shows (SPEC §13.6). */
class MonitorPresenter
{
    private const RUNS_SHOWN = 100;

    public function __construct(private CheckerRegistry $registry) {}

    /** "TCP port · SSH (22)", "HTTP", "HTTP · Shop". */
    public function name(Monitor $monitor): string
    {
        $name = $this->registry->name($monitor->checker).($monitor->label !== null ? " · {$monitor->label}" : '');

        if ($monitor->checker === 'server.tcp' && $this->registry->has($monitor->checker)) {
            $name .= ' ('.$this->registry->settings($monitor)->arguments->int('port').')';
        }

        return $name;
    }

    /**
     * Expects `monitorable` and `state` loaded.
     */
    public function row(Monitor $monitor): MonitorRowData
    {
        /** @var (Model&Monitorable)|null $resource */
        $resource = $monitor->monitorable;
        $state = $monitor->state;

        return new MonitorRowData(
            id: $monitor->id,
            checker: $monitor->checker,
            name: $this->name($monitor),
            resource: new MonitorResourceData($monitor->monitorable_type, $monitor->monitorable_id, $resource?->resourceName() ?? '(deleted)'),
            enabled: $monitor->enabled,
            muted_until: $monitor->isMuted() ? $monitor->muted_until?->toIso8601String() : null,
            status: $state->status ?? Status::Unknown,
            pending: $state?->isPending() === true,
            last_result_status: $state?->last_result_status,
            message: $state?->message,
            duration_ms: $state?->duration_ms,
            checked_at: $state?->checked_at?->toIso8601String(),
            next_run_at: $monitor->next_run_at?->toIso8601String(),
            interval: $this->interval($monitor),
        );
    }

    /**
     * A resource's monitors for its page, enabled first.
     *
     * @return list<MonitorRowData>
     */
    public function rowsFor(Model&Monitorable $resource): array
    {
        return array_values($resource->monitors()
            ->with(['state', 'monitorable'])
            ->orderByDesc('enabled')
            ->orderBy('checker')
            ->orderBy('label')
            ->get()
            ->map(fn (Monitor $monitor): MonitorRowData => $this->row($monitor))
            ->all());
    }

    public function detail(Monitor $monitor): MonitorDetailData
    {
        $monitor->loadMissing(['state', 'monitorable']);
        $state = $monitor->state;

        return new MonitorDetailData(
            monitor: $this->row($monitor),
            settings: $this->settings($monitor),
            consecutive_failures: $state->consecutive_failures ?? 0,
            consecutive_successes: $state->consecutive_successes ?? 0,
            consecutive_unknowns: $state->consecutive_unknowns ?? 0,
            last_definitive_at: $state?->last_definitive_at?->toIso8601String(),
            stale_at: $state?->stale_at?->toIso8601String(),
            runs: array_values($monitor->runs()->latest('checked_at')->latest('id')->limit(self::RUNS_SHOWN)->get()
                ->map(fn (MonitorRun $run): MonitorRunData => new MonitorRunData($run->id, $run->status, $run->duration_ms, $run->message, $run->checked_at->toIso8601String()))
                ->all()),
            form: $this->form($monitor),
            checker: $this->registry->has($monitor->checker) ? $this->checkerOption($monitor->checker, $monitor->monitorable) : null,
        );
    }

    public function form(Monitor $monitor): MonitorFormData
    {
        return new MonitorFormData(
            id: $monitor->id,
            monitorable_type: $monitor->monitorable_type,
            monitorable_id: $monitor->monitorable_id,
            checker: $monitor->checker,
            label: $monitor->label,
            arguments: $monitor->arguments ?? [],
            interval_minutes: $monitor->interval_minutes,
            failures_before_incident: $monitor->failures_before_incident,
            successes_before_resolve: $monitor->successes_before_resolve,
            enabled: $monitor->enabled,
        );
    }

    /**
     * Checkers a resource can get, with their schema for the form.
     *
     * @return list<CheckerOptionData>
     */
    public function checkerOptions(Model&Monitorable $resource): array
    {
        return array_map(
            fn (string $key): CheckerOptionData => $this->checkerOption($key, $resource),
            $this->registry->keysFor($resource->getMorphClass()),
        );
    }

    public function checkerOption(string $key, ?Model $resource): CheckerOptionData
    {
        $entry = $this->registry->entry($key);

        return new CheckerOptionData(
            key: $key,
            name: $this->registry->name($key),
            interval: $entry['interval'],
            failures_before_incident: $entry['failures_before_incident'],
            successes_before_resolve: $entry['successes_before_resolve'],
            arguments: array_map(fn (array $field): ArgumentSchemaData => new ArgumentSchemaData(...$field), $this->registry->schema($key, $resource)),
        );
    }

    /**
     * @return list<MonitorSettingData>
     */
    private function settings(Monitor $monitor): array
    {
        try {
            $resolved = $this->registry->settings($monitor);
            $schema = $this->registry->schema($monitor->checker, $monitor->monitorable);
        } catch (UnknownChecker) {
            return [];
        }

        $settings = [];

        foreach ($schema as $field) {
            $settings[] = new MonitorSettingData(
                $field['label'],
                $this->plain($resolved->arguments->all()[$field['name']] ?? null),
                ! array_key_exists($field['name'], $monitor->arguments ?? []),
            );
        }

        return [
            ...$settings,
            new MonitorSettingData('Every', $resolved->interval.' min', $monitor->interval_minutes === null),
            new MonitorSettingData('Failures before an incident', (string) $resolved->failuresBeforeIncident, $monitor->failures_before_incident === null),
            new MonitorSettingData('Passes before it resolves', (string) $resolved->successesBeforeResolve, $monitor->successes_before_resolve === null),
            new MonitorSettingData('Worst severity', ucfirst($resolved->maxSeverity->value), true),
        ];
    }

    private function interval(Monitor $monitor): int
    {
        try {
            return $this->registry->interval($monitor);
        } catch (UnknownChecker) {
            return $monitor->interval_minutes ?? 60;
        }
    }

    private function plain(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
            is_scalar($value) => (string) $value,
            default => '—',
        };
    }
}
