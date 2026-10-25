<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SpeedBump */
class SpeedBumpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getRouteKey(),
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius_m' => $this->radius_m,
            'heading_deg' => $this->heading_deg,
            'active' => $this->active,
            'notes' => $this->notes,
            'routes' => $this->relationLoaded('routes')
                ? $this->routes->map(fn ($r) => ['id' => $r->getRouteKey(), 'name' => $r->name])->values()->all()
                : [],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
