<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Alta de equipos: pocos intentos por IP (protege la clave de alta contra fuerza bruta).
        RateLimiter::for('device-enroll', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Sincronizacion: por token (cada Telpo sincroniza cada ~15 min; 30/min deja margen amplio).
        RateLimiter::for('device-sync', function (Request $request) {
            $token = (string) $request->bearerToken();

            return Limit::perMinute(30)->by($token !== '' ? hash('sha256', $token) : $request->ip());
        });

        // Peticiones del panel de administracion.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
    }
}
