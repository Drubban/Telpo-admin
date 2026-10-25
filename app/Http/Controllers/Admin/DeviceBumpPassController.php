<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\SpeedBump;
use App\Support\BillingCycle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Topes por los que paso (y avisó) una Telpo, agrupados por tope, en un rango de fechas. */
class DeviceBumpPassController extends Controller
{
    public function __invoke(Request $request, Device $device): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tz = BillingCycle::timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $fromLocal = isset($v['from']) ? CarbonImmutable::parse($v['from'], $tz)->startOfDay() : $today->subDays(6);
        $toLocal = isset($v['to']) ? CarbonImmutable::parse($v['to'], $tz)->startOfDay() : $today;
        $from = $fromLocal->utc()->toDateTimeString();
        $to = $toLocal->addDay()->utc()->toDateTimeString();

        $base = fn () => DeviceEvent::query()
            ->where('device_id', $device->id)
            ->where('type', EventType::BumpAlert->value)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to);

        // Agrupado por tope (id guardado en el detalle del evento).
        $grouped = $base()
            ->select(
                DB::raw("detail->>'bump_id' AS bump_id"),
                DB::raw("MAX(detail->>'bump_name') AS bump_name"),
                DB::raw('COUNT(*) AS passes'),
                DB::raw('MAX(occurred_at) AS last_at'),
                DB::raw('MIN(occurred_at) AS first_at'),
            )
            ->groupBy(DB::raw("detail->>'bump_id'"))
            ->orderByDesc('passes')
            ->get();

        $names = SpeedBump::query()->whereIn('id', $grouped->pluck('bump_id')->filter(fn ($i) => ctype_digit((string) $i))->all())
            ->pluck('name', 'id');

        $toIso = fn (?string $utc) => $utc ? CarbonImmutable::parse($utc, 'UTC')->toIso8601String() : null;

        $byBump = $grouped->map(fn ($r) => [
            'bump_id' => $r->bump_id,
            'name' => $names[(int) $r->bump_id] ?? ($r->bump_name ?: 'Tope #'.$r->bump_id),
            'removed' => ! isset($names[(int) $r->bump_id]),
            'passes' => (int) $r->passes,
            'first_at' => $toIso($r->first_at),
            'last_at' => $toIso($r->last_at),
        ])->values();

        $recent = $base()->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get()->map(fn (DeviceEvent $e) => [
            'name' => $e->detail['bump_name'] ?? ('Tope #'.($e->detail['bump_id'] ?? '?')),
            'distance_m' => $e->detail['distance_m'] ?? null,
            'at' => $e->occurred_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'range' => ['from' => $fromLocal->toDateString(), 'to' => $toLocal->toDateString()],
            'total' => (int) $byBump->sum('passes'),
            'distinct' => $byBump->count(),
            'by_bump' => $byBump,
            'recent' => $recent,
        ]);
    }
}
