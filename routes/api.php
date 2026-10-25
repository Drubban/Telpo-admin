<?php

use App\Http\Controllers\Device\EnrollController;
use App\Http\Controllers\Device\SyncController;
use Illuminate\Support\Facades\Route;

// API para las Telpo (la usa la app Android). El prefijo /api lo agrega Laravel.
Route::prefix('v1/device')->group(function () {
    Route::post('enroll', EnrollController::class)->middleware('throttle:device-enroll');
    Route::post('sync', SyncController::class)->middleware(['throttle:device-sync', 'device']);
});
