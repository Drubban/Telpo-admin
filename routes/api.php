<?php

use App\Http\Controllers\Device\EnrollController;
use App\Http\Controllers\Device\SyncController;
use App\Http\Controllers\Widget\SummaryController;
use Illuminate\Support\Facades\Route;

// API para las Telpo (la usa la app Android). El prefijo /api lo agrega Laravel.
Route::prefix('v1/device')->group(function () {
    Route::post('enroll', EnrollController::class)->middleware('throttle:device-enroll');
    Route::post('sync', SyncController::class)->middleware(['throttle:device-sync', 'device']);
});

// Resumen de solo lectura para widgets de escritorio (Rainmeter / Lumen). Apagado si no hay
// TELPO_WIDGET_TOKEN en .env. Clave por Bearer o ?token=.
Route::get('v1/widget/summary', SummaryController::class)->middleware(['throttle:widget', 'widget']);
