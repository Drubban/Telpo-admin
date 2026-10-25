<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CredentialKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCredentialRequest;
use App\Models\AuditLog;
use App\Models\DeviceCredential;
use App\Services\CredentialService;
use Illuminate\Http\JsonResponse;

class CredentialController extends Controller
{
    public function index(): JsonResponse
    {
        $stored = DeviceCredential::with('updatedBy:id,name')->get()->keyBy('kind');

        $items = array_map(function (CredentialKind $kind) use ($stored) {
            $credential = $stored->get($kind->value);

            return [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'is_set' => $credential !== null,
                'version' => $credential?->version,
                'updated_at' => $credential?->updated_at?->toIso8601String(),
                'updated_by' => $credential?->updatedBy?->name,
            ];
        }, CredentialKind::cases());

        return response()->json(['data' => $items]);
    }

    public function update(UpdateCredentialRequest $request, CredentialService $credentials): JsonResponse
    {
        $kind = $request->kind();
        $credential = $credentials->set($kind, $request->validated('new_password'), $request->user());

        // Nunca se registra la contrasena, solo que cambio y a que version.
        AuditLog::record('credential_changed', $request->user()->id, $kind->value, ['version' => $credential->version], $request->ip());

        return response()->json([
            'data' => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'is_set' => true,
                'version' => $credential->version,
                'updated_at' => $credential->updated_at?->toIso8601String(),
                'updated_by' => $request->user()->name,
            ],
            'message' => 'Contraseña actualizada. Los equipos la recibirán en su próxima sincronización.',
        ]);
    }
}
