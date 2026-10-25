<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Con TELPO_ALLOW_HTTP=true (red local sin HTTPS) no se envian HSTS ni CSP: el navegador
        // los guardaria y despues forzaria https en esa IP.
        if (app()->isProduction() && ! config('telpo.allow_http')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            // El build de Vite sirve todo desde el mismo origen. Vue necesita estilos en linea.
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
                ."connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
            );
        }

        return $response;
    }
}
