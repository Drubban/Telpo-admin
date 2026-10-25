<?php

namespace App\Http\Controllers\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncRequest;
use App\Services\DeviceSyncService;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    public function __invoke(SyncRequest $request, DeviceSyncService $sync): JsonResponse
    {
        // La validacion del sobre ignora los eventos (se validan uno a uno); se toman del request.
        $payload = $request->validated();
        $payload['events'] = (array) $request->input('events', []);

        return response()->json(
            $sync->handle($request->attributes->get('device'), $payload, $request->ip())
        );
    }
}
