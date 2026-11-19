<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el resumen para widgets con una clave fija (TELPO_WIDGET_TOKEN).
 * Acepta la clave como "Authorization: Bearer ..." o como ?token=... (Rainmeter solo hace GET simples).
 * Sin clave configurada la ruta no existe (404), asi queda apagada por defecto.
 */
class AuthenticateWidget
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('telpo.widget_token');
        if ($expected === '') {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $given = (string) ($request->bearerToken() ?: $request->query('token', ''));
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Token no valido'], 401);
        }

        return $next($request);
    }
}
