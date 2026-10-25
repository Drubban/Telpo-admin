<?php

namespace App\Http\Requests;

use App\Models\BumpRoute;
use App\Support\Hid;
use Illuminate\Foundation\Http\FormRequest;

/** Importacion de coordenadas pegadas como texto. Con dry_run solo se revisa, sin guardar. */
class BumpImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:40000'],
            'dry_run' => ['sometimes', 'boolean'],
            'radius_m' => ['nullable', 'integer', 'between:20,500'],
            'heading_deg' => ['nullable', 'integer', 'between:0,359'],
            'route_ids' => ['nullable', 'array', 'max:50'],
            'route_ids.*' => ['string', 'max:40'],
        ];
    }

    /** @return list<int> */
    public function routeIds(): array
    {
        $ids = [];
        foreach ((array) $this->input('route_ids', []) as $hash) {
            $id = Hid::decode(BumpRoute::HASH_SCOPE, is_string($hash) ? $hash : null);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values(BumpRoute::whereIn('id', array_values($ids))->pluck('id')->all());
    }
}
