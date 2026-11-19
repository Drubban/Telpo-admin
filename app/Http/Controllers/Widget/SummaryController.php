<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\BillingApp;
use App\Support\BillingCycle;
use App\Support\UsageBand;
use Illuminate\Http\JsonResponse;

/**
 * Resumen de solo lectura para widgets de escritorio (Rainmeter / Lumen).
 *
 * Devuelve numeros planos y textos ya armados para que el cliente no tenga que procesar JSON
 * complejo: equipos en linea, unidades que pasan el 75/90/100 % del paquete de datos en el periodo
 * actual y unidades con la app de cobro desactualizada. No expone IMEI completos ni ids.
 * El JSON va sin escapar acentos ni barras porque el cliente lo lee con expresiones regulares.
 */
class SummaryController extends Controller
{
    /** Umbral minimo (porcentaje del paquete) para aparecer en la lista de consumo alto. */
    private const HIGH_USAGE_PERCENT = 75;

    /** Cuantas unidades se listan como maximo en cada texto. */
    private const LIST_LIMIT = 5;

    public function __invoke(): JsonResponse
    {
        $cycle = BillingCycle::containing();
        $onlineSince = now()->subMinutes((int) config('telpo.online_minutes', 30));

        $total = Device::count();
        $online = Device::where('last_seen_at', '>', $onlineSince)->count();
        $nfcProblems = Device::where(fn ($w) => $w->where('nfc_enabled', false)->orWhere('camera_ok', false))->count();

        // --- Consumo del periodo actual ---
        $usage = Device::query()
            ->withCycleBytes($cycle->key())
            ->orderByDesc('cycle_bytes')
            ->get()
            ->map(fn (Device $d) => [
                'unit' => $this->unitLabel($d),
                'bytes' => (int) $d->cycle_bytes,
                'percent' => UsageBand::percent((int) $d->cycle_bytes),
                'band' => UsageBand::of((int) $d->cycle_bytes),
            ]);

        $high = $usage->filter(fn ($u) => $u['percent'] !== null && $u['percent'] >= self::HIGH_USAGE_PERCENT)->values();
        $over90 = $usage->filter(fn ($u) => $u['percent'] !== null && $u['percent'] >= 90)->count();
        $over100 = $usage->filter(fn ($u) => $u['percent'] !== null && $u['percent'] >= 100)->count();

        $top = $high->take(self::LIST_LIMIT)->map(fn ($u) => [
            'unit' => $u['unit'],
            'percent' => $u['percent'],
            'gb' => round($u['bytes'] / 1073741824, 1),
        ])->values();

        // --- Version de la app de cobro ---
        $target = BillingApp::target();
        $countState = fn (string $state) => Device::query()->billingState($state, $target['code'])->count();
        $outdatedUnits = Device::query()
            ->billingState('outdated', $target['code'])
            ->orderBy('unit_number')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Device $d) => $this->unitLabel($d))
            ->values();

        return response()->json([
            'cycle' => $cycle->label(),
            'cycle_days_elapsed' => $cycle->elapsedDays(),
            'devices_total' => $total,
            'devices_online' => $online,
            'devices_offline' => max(0, $total - $online),
            'nfc_problems' => $nfcProblems,
            'usage_over_75' => $high->count(),
            'usage_over_90' => $over90,
            'usage_over_100' => $over100,
            'usage_top' => $top,
            // Textos listos para mostrar: "U-12 95 % · U-40 91 %"
            'usage_top_text' => $top->map(fn ($u) => $u['unit'].' '.round($u['percent']).' %')->implode(' · '),
            'billing_target' => $target['name'] ?? ($target['code'] !== null ? (string) $target['code'] : null),
            'billing_current' => $countState('current'),
            'billing_outdated' => $countState('outdated'),
            'billing_missing' => $countState('missing'),
            'billing_unknown' => $countState('unknown'),
            'billing_outdated_text' => $outdatedUnits->implode(', '),
            'generated_at' => now()->toIso8601String(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Nombre corto de la unidad: numero, etiqueta o los ultimos 6 digitos del IMEI. */
    private function unitLabel(Device $device): string
    {
        if ($device->unit_number) {
            return (string) $device->unit_number;
        }
        if ($device->label) {
            return (string) $device->label;
        }

        return $device->imei ? '…'.substr((string) $device->imei, -6) : 'Sin unidad';
    }
}
