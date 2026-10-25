<?php

namespace App\Http\Requests;

use App\Enums\CredentialKind;
use App\Services\CredentialService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $min = (int) config('telpo.password_min_length', 6);

        return [
            'new_password' => ['required', 'string', "min:{$min}", 'max:64', 'confirmed'],
            // Re-autenticacion: cambiar una clave de equipos exige la clave del administrador web.
            'admin_password' => ['required', 'string', 'current_password'],
        ];
    }

    public function attributes(): array
    {
        return [
            'new_password' => 'nueva contraseña',
            'admin_password' => 'tu contraseña de acceso',
        ];
    }

    public function messages(): array
    {
        return [
            'new_password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'new_password.confirmed' => 'La confirmación no coincide.',
            'admin_password.current_password' => 'Tu contraseña de acceso no es correcta.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $kind = CredentialKind::tryFrom((string) $this->route('kind'));
                $plain = $this->input('new_password');

                if ($kind && is_string($plain) && ! $validator->errors()->has('new_password')
                    && app(CredentialService::class)->collidesWithOther($kind, $plain)) {
                    $validator->errors()->add('new_password', 'Las tres contraseñas deben ser distintas entre sí.');
                }
            },
        ];
    }

    public function kind(): CredentialKind
    {
        return CredentialKind::from((string) $this->route('kind'));
    }
}
