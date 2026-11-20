<?php

use App\Http\Controllers\ExternalServiceController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::inertia('/', 'Dashboard')->name('dashboard');

    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('external-services', [ExternalServiceController::class, 'index'])->name('external-services.index');

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
    });

    Route::get('servers/{server}', [ServerController::class, 'show'])->withTrashed()->name('servers.show');
});

require __DIR__.'/settings.php';
