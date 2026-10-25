<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enrollment_key' => ['required', 'string', 'max:200'],
            'install_id' => ['required', 'uuid'],
            'imei' => ['nullable', 'regex:/^\d{14,16}$/'],
            'unit_number' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pN _.\-\/#]+$/u'],
            'serial' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }
}
