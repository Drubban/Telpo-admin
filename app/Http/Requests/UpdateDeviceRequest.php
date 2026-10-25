<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'unit_number' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pN _.\-\/#]+$/u'],
            'label' => ['nullable', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return [
            'unit_number.regex' => 'La unidad solo admite letras, números, espacios y - _ . / #',
        ];
    }

    /** No se permite reactivar a mano un equipo "released": debe volver a darse de alta. */
    public function after(): array
    {
        return [
            function ($validator) {
                /** @var Device|null $device */
                $device = $this->route('device');
                if ($device && $device->status === 'released' && $this->has('status')) {
                    $validator->errors()->add('status', 'Un equipo liberado debe volver a configurarse en el equipo.');
                }
            },
        ];
    }
}
