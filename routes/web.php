<?php

use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::inertia('/', 'Dashboard')->name('dashboard');

    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');

    Route::middleware('admin')->group(function () {
        Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
        Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
        Route::get('servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
        Route::put('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
        Route::post('servers/{server}/restore', [ServerController::class, 'restore'])->withTrashed()->name('servers.restore');
    });

    Route::get('servers/{server}', [ServerController::class, 'show'])->withTrashed()->name('servers.show');
});

require __DIR__.'/settings.php';
