<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\EventIndexRequest;
use App\Http\Resources\EventResource;
use App\Models\DeviceEvent;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function index(EventIndexRequest $request): JsonResponse
    {
        $page = DeviceEvent::query()
            ->with('device:id,unit_number,imei,label')
            ->filter($request->filters())
            ->orderByDesc('device_events.occurred_at')
            ->orderByDesc('device_events.id')
            ->paginate((int) ($request->validated('per_page') ?? 20))
            ->withQueryString();

        return EventResource::collection($page)
            ->additional([
                'types' => array_map(
                    fn (EventType $t) => ['value' => $t->value, 'label' => $t->label()],
                    EventType::cases()
                ),
            ])
            ->response();
    }
}
