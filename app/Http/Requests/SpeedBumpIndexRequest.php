<?php

namespace App\Http\Requests;

use App\Models\BumpRoute;
use App\Support\Hid;
use Illuminate\Foundation\Http\FormRequest;

class SpeedBumpIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'route' => ['nullable', 'string', 'max:40'],
            'active' => ['nullable', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:15,30,50,100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $route = $this->validated('route');

        return [
            'q' => $this->validated('q'),
            'active' => $this->validated('active'),
            // Un hash invalido no filtra a "nada": se ignora el filtro.
            'route_id' => $route ? Hid::decode(BumpRoute::HASH_SCOPE, $route) : null,
        ];
    }
}
