<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Services\CredentialService;
use App\Support\BillingCycle;
use App\Support\Hid;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Datos del dashboard. Todos los visuales comparten el periodo elegido y, opcionalmente, un
 * equipo (filtro cruzado: al hacer clic en una barra los demas visuales se filtran).
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, CredentialService $credentials): JsonResponse
    {
        $cycle = BillingCycle::containing($request->validated('cycle'));
        $deviceHash = $request->validated('device');
        $deviceId = $deviceHash ? Hid::decode(Device::HASH_SCOPE, $deviceHash) : null;
        if ($deviceHash && $deviceId === null) {
            abort(404);
        }

        $from = $cycle->start->utc()->toDateTimeString();
        $to = $cycle->end->utc()->toDateTimeString();

        // --- Equipos ---
        $deviceQuery = Device::query();
        $total = (clone $deviceQuery)->count();
        $onlineSince = now()->subMinutes((int) config('telpo.online_minutes', 30));
        $online = (clone $deviceQuery)->where('last_seen_at', '>', $onlineSince)->count();
        $nfcProblems = (clone $deviceQuery)->where(fn ($w) => $w->where('nfc_enabled', false)->orWhere('camera_ok', false))->count();

        // --- Consumo ---
        $perDevice = DB::table('data_usage_daily as u')
            ->join('devices as d', 'd.id', '=', 'u.device_id')
            ->where('u.period_start', $cycle->key())
            ->when($deviceId, fn ($q) => $q->where('u.device_id', $deviceId))
            ->groupBy('u.device_id', 'd.unit_number', 'd.imei', 'd.label')
            ->select('u.device_id', 'd.unit_number', 'd.imei', 'd.label', DB::raw('MAX(u.total_bytes) AS bytes'))
            ->orderByDesc('bytes')
            ->get();

        $cycleBytes = (int) $perDevice->sum('bytes');
        $reporting = $perDevice->count();

        // Top 10 para el grafico de barras (siempre todos los equipos, aunque haya filtro cruzado,
        // para poder cambiar de seleccion).
        $topBase = $deviceId === null
            ? $perDevice
            : DB::table('data_usage_daily as u')
                ->join('devices as d', 'd.id', '=', 'u.device_id')
                ->where('u.period_start', $cycle->key())
                ->groupBy('u.device_id', 'd.unit_number', 'd.imei', 'd.label')
                ->select('u.device_id', 'd.unit_number', 'd.imei', 'd.label', DB::raw('MAX(u.total_bytes) AS bytes'))
                ->orderByDesc('bytes')
                ->get();

        $usageByUnit = $topBase->take(10)->map(fn ($r) => [
            'device' => Hid::encode(Device::HASH_SCOPE, (int) $r->device_id),
            'label' => $r->unit_number ?: ($r->label ?: ($r->imei ? '…'.substr($r->imei, -6) : 'Sin unidad')),
            'bytes' => (int) $r->bytes,
        ])->values();

        // Consumo diario = diferencia del acumulado contra el dia anterior de cada equipo.
        $daily = DB::table(
            DB::table('data_usage_daily')
                ->where('period_start', $cycle->key())
                ->when($deviceId, fn ($q) => $q->where('device_id', $deviceId))
                ->selectRaw('day, total_bytes - COALESCE(LAG(total_bytes) OVER (PARTITION BY device_id ORDER BY day), 0) AS delta'),
            't'
        )
            ->selectRaw('day, SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) AS bytes')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'bytes' => (int) $r->bytes])
            ->values();

        // --- Eventos del periodo ---
        $events = DeviceEvent::query()
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->when($deviceId, fn ($q) => $q->where('device_id', $deviceId));

        $byType = (clone $events)
            ->where('type', '!=', EventType::DataUsage->value)
            ->selectRaw('type, COUNT(*) AS total, SUM(CASE WHEN success = ? THEN 1 ELSE 0 END) AS failed', [false])
            ->groupBy('type')
            ->get()
            ->map(function ($r) {
                $type = EventType::tryFrom($r->type);

                return [
                    'type' => $r->type,
                    'label' => $type?->label() ?? $r->type,
                    'total' => (int) $r->total,
                    'failed' => (int) $r->failed,
                ];
            })->values();

        $dayExpr = Sql::localDay('occurred_at');
        $attempts = (clone $events)
            ->whereIn('type', EventType::attemptValues())
            ->selectRaw("{$dayExpr} AS day, SUM(CASE WHEN success = ? THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN success = ? THEN 1 ELSE 0 END) AS failed", [true, false])
            ->groupByRaw($dayExpr)
            ->orderByRaw($dayExpr)
            ->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'ok' => (int) $r->ok, 'failed' => (int) $r->failed])
            ->values();

        $failedAttempts = (int) $attempts->sum('failed');
        $okAttempts = (int) $attempts->sum('ok');

        return response()->json([
            'cycle' => $cycle->toArray(),
            'cycles' => array_map(fn (BillingCycle $c) => $c->toArray(), BillingCycle::recent()),
            'filter_device' => $deviceHash,
            'kpis' => [
                'devices_total' => $total,
                'devices_online' => $online,
                'devices_offline' => max(0, $total - $online),
                'nfc_problems' => $nfcProblems,
                'cycle_bytes' => $cycleBytes,
                'devices_reporting' => $reporting,
                'average_bytes' => $reporting ? intdiv($cycleBytes, $reporting) : 0,
                'attempts_failed' => $failedAttempts,
                'attempts_ok' => $okAttempts,
            ],
            'usage_by_unit' => $usageByUnit,
            'usage_daily' => $daily,
            'events_by_type' => $byType,
            'attempts_by_day' => $attempts,
            'credentials_missing' => $credentials->missingKinds(),
        ]);
    }
}
