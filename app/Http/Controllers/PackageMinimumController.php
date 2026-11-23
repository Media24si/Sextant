<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackageMinimumRequest;
use App\Models\PackageMinimum;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Package minimums, on the Minimum versions page (SPEC §11.9, §13.6).
 * Setting, changing or removing one re-reads every repository (the observer).
 */
class PackageMinimumController extends Controller
{
    public function store(PackageMinimumRequest $request): RedirectResponse
    {
        $minimum = new PackageMinimum($request->validated());
        $minimum->set_by = (int) $request->user()?->id;
        $minimum->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Minimum {$minimum->label()} set. Repositories are being checked again."]);

        return back();
    }

    public function update(PackageMinimumRequest $request, PackageMinimum $packageMinimum): RedirectResponse
    {
        $packageMinimum->fill($request->validated());
        $packageMinimum->set_by = (int) $request->user()?->id;
        $packageMinimum->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Minimum {$packageMinimum->label()} saved. Repositories are being checked again."]);

        return back();
    }

    public function destroy(PackageMinimum $packageMinimum): RedirectResponse
    {
        $packageMinimum->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Minimum {$packageMinimum->label()} removed."]);

        return back();
    }
}
