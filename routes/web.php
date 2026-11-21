<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ChoiceController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ExternalServiceController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\MuteController;
use App\Http\Controllers\RunChecksController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteImportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::inertia('/', 'Dashboard')->name('dashboard');

    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('external-services', [ExternalServiceController::class, 'index'])->name('external-services.index');
    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('settings/lists', [ChoiceController::class, 'index'])->name('lists.index');
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::get('monitors', [MonitorController::class, 'index'])->name('monitors.index');
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

        Route::post('monitors', [MonitorController::class, 'store'])->name('monitors.store');
        Route::put('monitors/{monitor}', [MonitorController::class, 'update'])->name('monitors.update');
        Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy'])->name('monitors.destroy');
        Route::post('monitors/{monitor}/run', [MonitorController::class, 'run'])->name('monitors.run');
        Route::post('monitors/{monitor}/mute', [MuteController::class, 'monitor'])->name('monitors.mute');
        Route::post('servers/{server}/mute', [MuteController::class, 'server'])->name('servers.mute');
        Route::post('domains/{domain}/mute', [MuteController::class, 'domain'])->name('domains.mute');
        Route::post('sites/{site}/mute', [MuteController::class, 'site'])->name('sites.mute');
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
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
    Route::get('monitors/{monitor}', [MonitorController::class, 'show'])->name('monitors.show');
});

require __DIR__.'/settings.php';
