<?php

namespace App\Http\Controllers\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollRequest;
use App\Services\CredentialService;
use App\Services\EnrollmentService;
use App\Support\BillingCycle;
use Illuminate\Http\JsonResponse;

class EnrollController extends Controller
{
    public function __invoke(EnrollRequest $request, EnrollmentService $enrollment, CredentialService $credentials): JsonResponse
    {
        $expected = (string) config('telpo.enrollment_key');

        // Sin clave de alta configurada en el servidor no se aceptan altas.
        if ($expected === '') {
            return response()->json(['message' => 'Alta de equipos no configurada'], 503);
        }

        if (! hash_equals($expected, (string) $request->input('enrollment_key'))) {
            return response()->json(['message' => 'Clave de alta incorrecta'], 403);
        }

        $result = $enrollment->enroll($request->validated(), $request->ip());

        // El alta ya se guardo: si las credenciales fallan, el equipo las recibe en su primer sync.
        try {
            $creds = $credentials->payloadFor([]);
        } catch (\Throwable $e) {
            report($e);
            $creds = [];
        }

        return response()->json([
            'device_token' => $result['token'],
            'unit_number' => $result['device']->unit_number,
            'credentials' => $creds,
            'cutoff_day' => BillingCycle::cutoffDay(),
        ], 201);
    }
}
