<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardBuilder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The live dashboard (SPEC §13.6, §13.7). Props are closures so the page's
 * poll reloads only what it asks for. Typed by `App\Data\Dashboard\DashboardData`.
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardBuilder $dashboard): Response
    {
        return Inertia::render('Dashboard', [
            'totals' => fn () => $dashboard->totals(),
            'issues' => fn () => $dashboard->issues(),
            'muted' => fn () => $dashboard->muted(),
            'pending_count' => fn () => $dashboard->pendingCount(),
            'resolved' => fn () => $dashboard->resolved(),
            'software' => fn () => $dashboard->software(),
            'expiring' => fn () => $dashboard->expiring(),
            'changes' => fn () => $dashboard->changes(),
            'system' => fn () => $dashboard->system(),
        ]);
    }
}
