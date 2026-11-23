<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ChoiceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ExternalServiceController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MinimumVersionController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\MuteController;
use App\Http\Controllers\RunChecksController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerVersionsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteImportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Opened by the server itself with the signed link from "Copy install command" (SPEC §11.8).
Route::get('servers/{server}/install.sh', [ServerVersionsController::class, 'install'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('servers.versions-install');

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('external-services', [ExternalServiceController::class, 'index'])->name('external-services.index');
    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('settings/lists', [ChoiceController::class, 'index'])->name('lists.index');
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::get('monitors', [MonitorController::class, 'index'])->name('monitors.index');
    Route::get('minimum-versions', [MinimumVersionController::class, 'index'])->name('minimum-versions.index');
    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::get('search', SearchController::class)->name('search');

    Route::middleware('admin')->group(function () {
        Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
        Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
        Route::get('servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
        Route::put('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
        Route::post('servers/{server}/restore', [ServerController::class, 'restore'])->withTrashed()->name('servers.restore');

        Route::get('external-services/create', [ExternalServiceController::class, 'create'])->name('external-services.create');
        Route::post('external-services', [ExternalServiceController::class, 'store'])->name('external-services.store');
        Route::get('external-services/{external_service}/edit', [ExternalServiceController::class, 'edit'])->name('external-services.edit');
        Route::put('external-services/{external_service}', [ExternalServiceController::class, 'update'])->name('external-services.update');
        Route::delete('external-services/{external_service}', [ExternalServiceController::class, 'destroy'])->name('external-services.destroy');
        Route::post('external-services/{external_service}/restore', [ExternalServiceController::class, 'restore'])->withTrashed()->name('external-services.restore');

        Route::get('domains/create', [DomainController::class, 'create'])->name('domains.create');
        Route::post('domains/lookup', [DomainController::class, 'lookup'])->name('domains.lookup');
        Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
        Route::get('domains/{domain}/edit', [DomainController::class, 'edit'])->name('domains.edit');
        Route::put('domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
        Route::post('domains/{domain}/restore', [DomainController::class, 'restore'])->withTrashed()->name('domains.restore');

        Route::get('applications/create', [ApplicationController::class, 'create'])->name('applications.create');
        Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
        Route::get('applications/{application}/edit', [ApplicationController::class, 'edit'])->name('applications.edit');
        Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
        Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');
        Route::post('applications/{application}/restore', [ApplicationController::class, 'restore'])->withTrashed()->name('applications.restore');

        Route::get('sites/create', [SiteController::class, 'create'])->name('sites.create');
        Route::get('sites/import', [SiteImportController::class, 'create'])->name('sites.import');
        Route::post('sites/import', [SiteImportController::class, 'store'])->name('sites.import.store');
        Route::post('sites/detect', [SiteController::class, 'detect'])->name('sites.detect');
        Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
        Route::get('sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
        Route::put('sites/{site}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
        Route::post('sites/{site}/restore', [SiteController::class, 'restore'])->withTrashed()->name('sites.restore');
        Route::post('sites/{site}/detect-again', [SiteController::class, 'detectAgain'])->name('sites.detect-again');
        Route::put('sites/{site}/path', [SiteController::class, 'updatePath'])->name('sites.path');

        Route::post('minimum-versions', [MinimumVersionController::class, 'store'])->name('minimum-versions.store');
        Route::put('minimum-versions/{minimum_version}', [MinimumVersionController::class, 'update'])->name('minimum-versions.update');
        Route::delete('minimum-versions/{minimum_version}', [MinimumVersionController::class, 'destroy'])->name('minimum-versions.destroy');

        Route::post('monitors', [MonitorController::class, 'store'])->name('monitors.store');
        Route::put('monitors/{monitor}', [MonitorController::class, 'update'])->name('monitors.update');
        Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy'])->name('monitors.destroy');
        Route::post('monitors/{monitor}/run', [MonitorController::class, 'run'])->name('monitors.run');
        Route::post('monitors/{monitor}/mute', [MuteController::class, 'monitor'])->name('monitors.mute');
        Route::post('servers/{server}/mute', [MuteController::class, 'server'])->name('servers.mute');
        Route::post('domains/{domain}/mute', [MuteController::class, 'domain'])->name('domains.mute');
        Route::post('sites/{site}/mute', [MuteController::class, 'site'])->name('sites.mute');
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
        Route::post('servers/{server}/versions-token', [ServerVersionsController::class, 'token'])->name('servers.versions-token');
        Route::post('servers/{server}/versions/install-command', [ServerVersionsController::class, 'installCommand'])->name('servers.versions.install-command');
        Route::get('servers/{server}/collector.sh', [ServerVersionsController::class, 'collector'])->name('servers.collector');
        Route::get('servers/{server}/versions.php', [ServerVersionsController::class, 'endpoint'])->name('servers.versions-php');
        Route::post('servers/{server}/versions/test', [ServerVersionsController::class, 'test'])->name('servers.versions.test');
        Route::post('servers/{server}/versions/refresh', [ServerVersionsController::class, 'refresh'])->name('servers.versions.refresh');
        Route::post('servers/{server}/run-checks', [RunChecksController::class, 'server'])->name('servers.run-checks');
        Route::post('domains/{domain}/run-checks', [RunChecksController::class, 'domain'])->name('domains.run-checks');
        Route::post('sites/{site}/run-checks', [RunChecksController::class, 'site'])->name('sites.run-checks');

        Route::post('settings/lists', [ChoiceController::class, 'store'])->name('lists.store');
        Route::put('settings/lists/{choice}', [ChoiceController::class, 'update'])->name('lists.update');
        Route::post('settings/lists/{choice}/move', [ChoiceController::class, 'move'])->name('lists.move');
        Route::delete('settings/lists/{choice}', [ChoiceController::class, 'destroy'])->name('lists.destroy');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    Route::get('servers/{server}', [ServerController::class, 'show'])->withTrashed()->name('servers.show');
    Route::get('domains/{domain}', [DomainController::class, 'show'])->withTrashed()->name('domains.show');
    Route::get('sites/{site}', [SiteController::class, 'show'])->withTrashed()->name('sites.show');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->withTrashed()->name('applications.show');
    Route::get('external-services/{external_service}', [ExternalServiceController::class, 'show'])->withTrashed()->name('external-services.show');
    Route::get('monitors/{monitor}', [MonitorController::class, 'show'])->name('monitors.show');
});

require __DIR__.'/settings.php';
