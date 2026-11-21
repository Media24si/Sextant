<?php

namespace App\Http\Controllers;

use App\Data\MinimumVersionRowData;
use App\Enums\Software;
use App\Http\Requests\MinimumVersionRequest;
use App\Models\MinimumVersion;
use App\Models\ServerSoftware;
use App\Versions\VersionComparator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minimum versions (SPEC §13.6, §11.6). Setting, changing or removing one
 * re-runs every versions check (the observer, §6.4).
 */
class MinimumVersionController extends Controller
{
    public function index(): Response
    {
        $readings = ServerSoftware::query()->with('server')->whereHas('server')->get();

        return Inertia::render('minimum-versions/Index', [
            'minimums' => MinimumVersion::query()->with('setBy')->orderBy('software')->orderBy('branch')->get()
                ->map(fn (MinimumVersion $minimum): MinimumVersionRowData => MinimumVersionRowData::fromModel($minimum, array_values($readings
                    ->filter(fn (ServerSoftware $row): bool => $row->software === $minimum->software
                        && ($minimum->software !== Software::Php || $row->branch === $minimum->branch)
                        && VersionComparator::isBelow($row->software, $row->version, $minimum->min_version))
                    ->map(fn (ServerSoftware $row): array => ['id' => $row->server_id, 'name' => $row->server->name, 'version' => $row->version])
                    ->all()))),
            'software' => array_values(array_map(
                fn (Software $software): array => ['value' => $software->value, 'label' => $software->label()],
                array_filter(Software::cases(), fn (Software $software): bool => $software->hasMinimum()),
            )),
            // PHP branches seen on servers, to pick from.
            'php_branches' => $readings->where('software', Software::Php)->pluck('branch')->unique()->sort()->values()->all(),
        ]);
    }

    public function store(MinimumVersionRequest $request): RedirectResponse
    {
        $minimum = new MinimumVersion($request->validated());
        $minimum->set_by = (int) $request->user()?->id;
        $minimum->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Minimum {$minimum->label()} set. Versions are being checked again."]);

        return back();
    }

    public function update(MinimumVersionRequest $request, MinimumVersion $minimumVersion): RedirectResponse
    {
        $minimumVersion->fill($request->validated());
        $minimumVersion->set_by = (int) $request->user()?->id;
        $minimumVersion->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Minimum {$minimumVersion->label()} saved. Versions are being checked again."]);

        return back();
    }

    public function destroy(Request $request, MinimumVersion $minimumVersion): RedirectResponse
    {
        $minimumVersion->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Minimum {$minimumVersion->label()} removed."]);

        return back();
    }
}
