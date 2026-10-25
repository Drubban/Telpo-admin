<?php

namespace App\Http\Requests;

use App\Models\BumpRoute;
use App\Support\Geo;
use App\Support\Hid;
use Illuminate\Foundation\Http\FormRequest;

/** Alta y edicion de un tope. */
class SpeedBumpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', "regex:/^[\pL\pN _.,\-\/#()&'\x{00B0}+]+$/u"],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'between:20,500'],
            'heading_deg' => ['nullable', 'integer', 'between:0,359'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
            'route_ids' => ['nullable', 'array', 'max:50'],
            'route_ids.*' => ['string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'El nombre solo admite letras, numeros, espacios y . , - / # ( ) & + \'',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
            'radius_m.between' => 'El radio de aviso debe estar entre 20 y 500 metros.',
            'heading_deg.between' => 'El sentido debe estar entre 0 y 359 grados.',
        ];
    }

    /** Coordenadas dentro de la zona de operacion; si estan invertidas se avisa. */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($validator->errors()->hasAny(['latitude', 'longitude'])) {
                    return;
                }
                $lat = (float) $this->input('latitude');
                $lng = (float) $this->input('longitude');
                if (! Geo::inBounds($lat, $lng)) {
                    $validator->errors()->add(
                        'latitude',
                        Geo::inBounds($lng, $lat)
                            ? 'Latitud y longitud parecen invertidas.'
                            : 'Las coordenadas quedan fuera de la zona de operacion.'
                    );
                }
            },
            function ($validator) {
                if ($validator->errors()->has('route_ids') || $validator->errors()->has('route_ids.*')) {
                    return;
                }
                if (count($this->routeIds()) !== count((array) $this->input('route_ids', []))) {
                    $validator->errors()->add('route_ids', 'Alguna ruta no existe.');
                }
            },
        ];
    }

    /** IDs reales de las rutas indicadas (los hashes invalidos o inexistentes se descartan). @return list<int> */
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
