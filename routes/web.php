<?php

use App\Http\Controllers\DemoTableController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::inertia('/', 'Dashboard')->name('dashboard');

    // Temporary DataTable demo (P0.11), removed with P1.10.
    Route::get('demo/table', [DemoTableController::class, 'index'])->name('demo.table');
    Route::post('demo/table/ping', [DemoTableController::class, 'ping'])
        ->middleware('admin')
        ->name('demo.table.ping');
});

require __DIR__.'/settings.php';
