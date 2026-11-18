<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CredentialKind;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\TempCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TempCodeController extends Controller
{
    /** Genera una contrasena temporal (15 min) para un equipo. Nunca se guarda ni se registra el codigo. */
    public function store(Request $request, Device $device, string $kind, TempCodeService $service): JsonResponse
    {
        $credentialKind = CredentialKind::tryFrom($kind);
        abort_unless($credentialKind !== null && TempCodeService::allows($credentialKind), 404);

        if ($device->status !== 'active') {
            return response()->json(['message' => 'El equipo está desactivado o liberado: no puede usar contraseñas temporales.'], 409);
        }

        $issued = $service->issue($device, $credentialKind);

        AuditLog::record('temp_code_issued', $request->user()->id, $device->getRouteKey(), [
            'kind' => $credentialKind->value,
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ], $request->ip());

        return response()
            ->json(['data' => [
                'code' => $issued['code'],
                'kind' => $credentialKind->value,
                'label' => $credentialKind->label(),
                'issued_at' => $issued['issued_at']->toIso8601String(),
                'expires_at' => $issued['expires_at']->toIso8601String(),
                'valid_minutes' => TempCodeService::VALID_MINUTES,
                'device_ready' => $issued['device_ready'],
            ]])
            ->header('Cache-Control', 'no-store');
    }
}
