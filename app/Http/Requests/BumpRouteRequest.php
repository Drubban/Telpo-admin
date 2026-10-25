<?php

namespace App\Http\Requests;

use App\Models\BumpRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BumpRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        /** @var BumpRoute|null $current */
        $current = $this->route('bumpRoute');

        return [
            'name' => [
                'required', 'string', 'max:60',
                "regex:/^[\pL\pN _.,\-\/#()&'+]+$/u",
                Rule::unique('bump_routes', 'name')->ignore($current?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una ruta con ese nombre.',
            'name.regex' => 'El nombre solo admite letras, numeros, espacios y . , - / # ( ) & +',
        ];
    }
}
