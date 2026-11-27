<?php

namespace App\Http\Controllers;

use App\Checks\Argument;
use App\Checks\CheckerRegistry;
use App\Data\Monitors\ArgumentSchemaData;
use App\Data\Monitors\CheckerDefaultsData;
use App\Http\Requests\CheckerSettingRequest;
use App\Models\CheckerSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monitor defaults: per checker, the arguments and thresholds every monitor
 * of that checker inherits unless it overrides them. Stored on top of
 * `config/checkers.php`; clearing every field goes back to the config.
 * Admins only.
 */
class CheckerSettingController extends Controller
{
    public function index(CheckerRegistry $registry): Response
    {
        $stored = CheckerSetting::query()->get()->keyBy('checker');

        $checkers = [];

        foreach ($registry->configEntries() as $key => $entry) {
            if (! class_exists($entry['class'])) {
                continue;
            }

            /** @var CheckerSetting|null $setting */
            $setting = $stored->get($key);
            $schema = array_values(array_filter($entry['class']::arguments(), fn (Argument $argument): bool => ! $argument->isPerResource()));

            $checkers[] = new CheckerDefaultsData(
                key: $key,
                name: $registry->name($key),
                resource_type: explode('.', $key, 2)[0],
                interval: $entry['interval'],
                failures_before_incident: $entry['failures_before_incident'],
                successes_before_resolve: $entry['successes_before_resolve'],
                arguments: array_map(fn (Argument $argument): ArgumentSchemaData => new ArgumentSchemaData(...$argument->toArray(null, $entry['arguments'][$argument->name] ?? null)), $schema),
                overrides: array_intersect_key($setting->arguments ?? [], array_flip(array_map(fn (Argument $argument): string => $argument->name, $schema))),
                interval_minutes: $setting?->interval_minutes,
                failures_before_incident_override: $setting?->failures_before_incident,
                successes_before_resolve_override: $setting?->successes_before_resolve,
            );
        }

        return Inertia::render('checker-settings/Index', ['checkers' => $checkers]);
    }

    public function update(CheckerSettingRequest $request, string $checker, CheckerRegistry $registry): RedirectResponse
    {
        abort_unless($registry->has($checker), 404);

        $values = [
            'arguments' => $request->arguments(),
            'interval_minutes' => $request->validated('interval_minutes'),
            'failures_before_incident' => $request->validated('failures_before_incident'),
            'successes_before_resolve' => $request->validated('successes_before_resolve'),
        ];

        $name = $registry->name($checker);

        if (array_filter($values, fn (mixed $value): bool => $value !== null) === []) {
            CheckerSetting::query()->where('checker', $checker)->first()?->delete();
            Inertia::flash('toast', ['type' => 'success', 'message' => "{$name} defaults reset to the config values."]);
        } else {
            CheckerSetting::query()->updateOrCreate(['checker' => $checker], $values);
            Inertia::flash('toast', ['type' => 'success', 'message' => "{$name} defaults saved. Monitors use them from their next run."]);
        }

        return back();
    }
}
