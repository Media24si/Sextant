<?php

namespace App\Http\Middleware;

use App\Dashboard\DashboardBuilder;
use App\Data\AuthUserData;
use App\Data\Dashboard\SystemHealthData;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? AuthUserData::fromModel($user) : null,
            ],
            'can' => [
                'manage' => $user?->can('manage') ?? false,
            ],
            // Sidebar counts (SPEC §13.4); more join as their pages arrive.
            'nav' => fn (): ?array => $user ? [
                'servers' => Server::query()->count(),
                'domains' => Domain::query()->count(),
                'incidents' => Incident::query()->open()->roots()->count(),
            ] : null,
            // The header's health pill on every page (SPEC §13.4); the dashboard also polls it.
            'system' => fn (): ?SystemHealthData => $user ? app(DashboardBuilder::class)->system() : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
