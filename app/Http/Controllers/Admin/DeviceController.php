<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceIndexRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Support\BillingCycle;
use App\Support\BumpCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    public function index(DeviceIndexRequest $request): JsonResponse
    {
        $cycle = BillingCycle::containing($request->validated('cycle'));

        $query = Device::query()
            ->withCycleBytes($cycle->key())
            ->filter($request->filters());

        // La columna de orden sale de una lista cerrada (DeviceIndexRequest::SORTABLE).
        $sort = $request->validated('sort') ?? 'unit_number';
        $dir = $request->validated('dir') === 'desc' ? 'desc' : 'asc';
        $column = $sort === 'cycle_bytes' ? 'cycle_bytes' : "devices.{$sort}";
        $query->orderBy($column, $dir)->orderBy('devices.id');

        $page = $query->paginate((int) ($request->validated('per_page') ?? 15))->withQueryString();

        return DeviceResource::collection($page)
            ->additional(['cycle' => $cycle->toArray(), 'units' => $this->units()])
            ->response();
    }

    public function show(Device $device, Request $request): JsonResponse
    {
        $cycle = BillingCycle::containing($request->query('cycle'));
        $withCycle = Device::query()->withCycleBytes($cycle->key())->whereKey($device->id)->firstOrFail();

        return response()->json([
            'data' => (new DeviceResource($withCycle))->resolve($request),
            'cycle' => $cycle->toArray(),
            'bumps_version' => BumpCatalog::version(),
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $data = $request->validated();
        $before = $device->only(['unit_number', 'label', 'status']);

        if (array_key_exists('unit_number', $data) && $data['unit_number'] !== $device->unit_number) {
            $device->unit_number = $data['unit_number'];
            // La Telpo adopta este valor en su siguiente sincronizacion (el cambio mas reciente gana).
            $device->unit_updated_at = CarbonImmutable::now('UTC');
        }
        if (array_key_exists('label', $data)) {
            $device->label = $data['label'];
        }
        if (isset($data['status'])) {
            $device->status = $data['status'];
        }
        $device->save();

        AuditLog::record('device_updated', $request->user()->id, $device->getRouteKey(), [
            'before' => $before,
            'after' => $device->only(['unit_number', 'label', 'status']),
        ], $request->ip());

        return response()->json(['data' => (new DeviceResource($device))->resolve($request)]);
    }

    /** Consumo diario del equipo en un periodo. */
    public function usage(Device $device, Request $request): JsonResponse
    {
        $cycle = BillingCycle::containing($request->query('cycle'));

        $rows = DB::table('data_usage_daily')
            ->where('device_id', $device->id)
            ->where('period_start', $cycle->key())
            ->orderBy('day')
            ->get(['day', 'total_bytes']);

        $previous = 0;
        $days = [];
        foreach ($rows as $row) {
            $total = (int) $row->total_bytes;
            $days[] = [
                'day' => (string) $row->day,
                'bytes' => max(0, $total - $previous),
                'cumulative_bytes' => $total,
            ];
            $previous = $total;
        }

        return response()->json([
            'cycle' => $cycle->toArray(),
            'cycles' => array_map(fn (BillingCycle $c) => $c->toArray(), BillingCycle::recent()),
            'days' => $days,
            'total_bytes' => $previous,
        ]);
    }

    /** @return list<string> Unidades distintas, para el filtro. */
    private function units(): array
    {
        return Device::whereNotNull('unit_number')->distinct()->orderBy('unit_number')->limit(500)->pluck('unit_number')->all();
    }
}
