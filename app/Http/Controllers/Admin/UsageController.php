<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceIndexRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Services\UsageAlertService;
use App\Support\BillingCycle;
use App\Support\UsageBand;
use Illuminate\Http\JsonResponse;

/** Tabla de consumo por equipo en un periodo (la misma consulta de equipos, ordenada por consumo). */
class UsageController extends Controller
{
    public function index(DeviceIndexRequest $request): JsonResponse
    {
        $cycle = BillingCycle::containing($request->validated('cycle'));
        $filters = $request->filters();

        $dir = $request->validated('dir') === 'asc' ? 'asc' : 'desc';
        $sort = $request->validated('sort');
        $column = in_array($sort, ['unit_number', 'imei'], true) ? "devices.{$sort}" : 'cycle_bytes';

        $band = $request->validated('band');

        $page = UsageBand::apply(Device::query()->withCycleBytes($cycle->key())->filter($filters), $band)
            ->orderBy($column, $sort === null ? 'desc' : $dir)
            ->orderBy('devices.id')
            ->paginate((int) ($request->validated('per_page') ?? 15))
            ->withQueryString();

        // Totales de todo el filtro (no solo de la pagina visible).
        // Conteo por categoria: sobre el filtro SIN la categoria, para que los chips sirvan de selector.
        $all = Device::query()->withCycleBytes($cycle->key())->filter($filters)->get();
        $bandCounts = array_fill_keys(UsageBand::keys(), 0);
        foreach ($all as $d) {
            $bandCounts[(string) UsageBand::of((int) $d->cycle_bytes)]++;
        }
        $totals = ($band === null || $band === '')
            ? $all
            : $all->filter(fn ($d) => (string) UsageBand::of((int) $d->cycle_bytes) === (string) $band)->values();
        $totalBytes = (int) $totals->sum('cycle_bytes');
        $elapsed = $cycle->elapsedDays();

        return DeviceResource::collection($page)
            ->additional([
                'cycle' => $cycle->toArray(),
                'plan' => [
                    'gb' => (float) config('telpo.data_plan_gb'),
                    'limit_bytes' => UsageAlertService::limitBytes(),
                    'bands' => array_map(fn (string $k) => [
                        'key' => $k, 'label' => UsageBand::label((int) $k), 'count' => $bandCounts[$k],
                    ], UsageBand::keys()),
                ],
                'cycles' => array_map(fn (BillingCycle $c) => $c->toArray(), BillingCycle::recent()),
                'summary' => [
                    'total_bytes' => $totalBytes,
                    'devices' => $totals->count(),
                    'reporting' => $totals->where('cycle_bytes', '>', 0)->count(),
                    'average_bytes' => $totals->count() ? intdiv($totalBytes, $totals->count()) : 0,
                    'elapsed_days' => $elapsed,
                ],
            ])
            ->response();
    }
}
