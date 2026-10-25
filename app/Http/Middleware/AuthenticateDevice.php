<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Autentica a la Telpo con su token personal (Authorization: Bearer ...). */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return response()->json(['message' => 'Token no valido'], 401);
        }

        $device = Device::where('token_hash', hash('sha256', $token))->first();
        if ($device === null) {
            return response()->json(['message' => 'Token no valido'], 401);
        }

        if ($device->status === 'inactive') {
            return response()->json(['message' => 'Equipo desactivado'], 403);
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
