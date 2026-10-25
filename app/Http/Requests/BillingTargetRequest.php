<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BillingTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** version_code vacio = volver al modo automatico (la mas alta que reporte la flota). */
    public function rules(): array
    {
        return [
            'version_code' => ['nullable', 'integer', 'min:1', 'max:2000000000'],
            'version_name' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pN ._\-+]+$/u'],
        ];
    }
}
