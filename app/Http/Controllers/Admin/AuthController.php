<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $request->authenticate();
        } catch (ValidationException $e) {
            AuditLog::record('login_failed', null, null, ['email' => mb_substr((string) $request->input('email'), 0, 120)], $request->ip());
            throw $e;
        }

        // Nuevo ID de sesion tras autenticarse: evita la fijacion de sesion.
        $request->session()->regenerate();
        AuditLog::record('login', Auth::id(), null, [], $request->ip());

        return response()->json($this->userPayload($request));
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLog::record('logout', Auth::id(), null, [], $request->ip());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request));
    }

    private function userPayload(Request $request): array
    {
        $user = $request->user();

        return ['name' => $user->name, 'email' => $user->email];
    }
}
