<?php

use App\Enums\CredentialKind;
use App\Http\Controllers\Admin\AppVersionController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BumpRouteController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceBumpPassController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\SpeedBumpController;
use App\Http\Controllers\Admin\UsageAlertController;
use App\Http\Controllers\Admin\UsageController;
use Illuminate\Support\Facades\Route;

// API del panel (sesion + CSRF). Los ids de equipos y eventos viajan hasheados.
Route::prefix('api/admin')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware(['auth', 'throttle:admin'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('dashboard', DashboardController::class);

        Route::get('devices', [DeviceController::class, 'index']);
        Route::get('devices/{device}', [DeviceController::class, 'show']);
        Route::patch('devices/{device}', [DeviceController::class, 'update']);
        Route::get('devices/{device}/usage', [DeviceController::class, 'usage']);
        Route::get('devices/{device}/bump-passes', DeviceBumpPassController::class);

        Route::get('usage', [UsageController::class, 'index']);
        Route::get('events', [EventController::class, 'index']);

        // Version de la app de cobro por unidad y version objetivo.
        Route::get('app-versions', [AppVersionController::class, 'index']);
        Route::put('app-versions/target', [AppVersionController::class, 'updateTarget']);

        // Alertas de consumo del paquete de datos. Las rutas fijas van antes que las que llevan {alert}.
        Route::get('usage-alerts', [UsageAlertController::class, 'index']);
        Route::get('usage-alerts/summary', [UsageAlertController::class, 'summary']);
        Route::post('usage-alerts/seen', [UsageAlertController::class, 'markAllSeen']);
        Route::post('usage-alerts/{alert}/seen', [UsageAlertController::class, 'markSeen']);
        Route::post('usage-alerts/{alert}/resend', [UsageAlertController::class, 'resend']);

        Route::get('credentials', [CredentialController::class, 'index']);
        Route::put('credentials/{kind}', [CredentialController::class, 'update'])
            ->whereIn('kind', CredentialKind::values());

        // Topes (reductores de velocidad). Las rutas fijas van antes que las que llevan {bump}.
        Route::get('speed-bumps', [SpeedBumpController::class, 'index']);
        Route::get('speed-bumps/map', [SpeedBumpController::class, 'map']);
        Route::post('speed-bumps/import', [SpeedBumpController::class, 'import']);
        Route::post('speed-bumps', [SpeedBumpController::class, 'store']);
        Route::patch('speed-bumps/{bump}', [SpeedBumpController::class, 'update']);
        Route::delete('speed-bumps/{bump}', [SpeedBumpController::class, 'destroy']);

        Route::get('bump-routes', [BumpRouteController::class, 'index']);
        Route::post('bump-routes', [BumpRouteController::class, 'store']);
        Route::patch('bump-routes/{bumpRoute}', [BumpRouteController::class, 'update']);
        Route::delete('bump-routes/{bumpRoute}', [BumpRouteController::class, 'destroy']);

        Route::get('export', ExportController::class);
    });
});

// El resto de rutas las resuelve la SPA de Vue (vue-router en modo historial).
Route::view('/{any?}', 'app')->where('any', '^(?!api/|up$).*$');
