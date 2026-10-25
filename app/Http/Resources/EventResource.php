<?php

namespace App\Http\Resources;

use App\Enums\EventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DeviceEvent */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = EventType::tryFrom($this->type);

        return [
            'id' => $this->getRouteKey(),
            'type' => $this->type,
            'type_label' => $type?->label() ?? $this->type,
            'success' => $this->success,
            'detail' => $this->detail ?? [],
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'device' => $this->whenLoaded('device', fn () => [
                'id' => $this->device->getRouteKey(),
                'unit_number' => $this->device->unit_number,
                'imei' => $this->device->imei,
                'label' => $this->device->label,
            ]),
        ];
    }
}
