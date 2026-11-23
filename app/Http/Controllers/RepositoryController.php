<?php

namespace App\Http\Controllers;

use App\Data\Repositories\RepositoryPackageData;
use App\Models\Repository;
use App\Models\RepositoryPackage;
use App\Monitoring\IncidentPresenter;
use App\Monitoring\MonitorProps;
use App\Packages\PackageMinimums;
use App\Packages\RepositoryPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Repositories (SPEC §3.21, §13.6): the code behind sites and applications,
 * with the packages locked in it. Created and removed with the GitHub links;
 * readers can see everything, admins can read one again now.
 */
class RepositoryController extends Controller
{
    public function index(RepositoryPresenter $presenter): Response
    {
        $repositories = Repository::query()->withCount('packages')->orderBy('owner')->orderBy('name')->get();

        return Inertia::render('repositories/Index', [
            'repositories' => $presenter->rows($repositories),
        ]);
    }

    public function show(Repository $repository, RepositoryPresenter $presenter, PackageMinimums $minimums, IncidentPresenter $incidents): Response
    {
        $repository->loadCount('packages');

        return Inertia::render('repositories/Show', [
            ...MonitorProps::for($repository),
            'repository' => $presenter->rows(collect([$repository]))[0],
            'packages' => $repository->packages()->get()
                ->map(fn (RepositoryPackage $package): RepositoryPackageData => RepositoryPackageData::fromModel($package, $minimums))
                ->values(),
            'incidents' => $incidents->latestFor($repository),
        ]);
    }
}
