<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageAlert;
use App\Services\UsageAlertService;
use App\Support\BillingCycle;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Alertas de consumo del paquete de datos. */
class UsageAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'threshold' => ['nullable', Rule::in(array_map('strval', (array) config('telpo.alert_thresholds')))],
            'cycle' => ['nullable', 'date_format:Y-m-d'],
            'unseen' => ['nullable', Rule::in(['1'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $cycle = BillingCycle::containing($v['cycle'] ?? null);

        $query = UsageAlert::query()
            ->with('device:id,unit_number,imei,label')
            ->where('silent', false)
            ->where('period_start', $cycle->key());

        if (! empty($v['threshold'])) {
            $query->where('threshold', (int) $v['threshold']);
        }
        if (! empty($v['unseen'])) {
            $query->whereNull('seen_at');
        }
        if (! empty($v['q'])) {
            $like = Sql::like($v['q']);
            $query->whereHas('device', fn ($d) => $d
                ->whereRaw("LOWER(COALESCE(devices.imei, '')) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(COALESCE(devices.unit_number, '')) LIKE ? ESCAPE '\\'", [$like]));
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 15))->withQueryString();

        $rows = $page->getCollection()->map(fn (UsageAlert $a) => $this->present($a))->values();
        $page->setCollection($rows);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
            'cycle' => $cycle->toArray(),
            'cycles' => array_map(fn (BillingCycle $c) => $c->toArray(), BillingCycle::recent()),
            'thresholds' => array_values((array) config('telpo.alert_thresholds')),
            'plan_gb' => (float) config('telpo.data_plan_gb'),
            'unseen' => UsageAlert::where('silent', false)->whereNull('seen_at')->count(),
            'channels' => [
                'email' => (array) config('telpo.alert_emails') !== [],
                'whatsapp' => config('telpo.whatsapp.driver') !== 'none' && (array) config('telpo.alert_whatsapp') !== [],
            ],
        ]);
    }

    /** Solo el contador de sin leer (para la barra lateral). */
    public function summary(): JsonResponse
    {
        return response()->json(['unseen' => UsageAlert::where('silent', false)->whereNull('seen_at')->count()]);
    }

    public function markSeen(UsageAlert $alert): JsonResponse
    {
        $alert->forceFill(['seen_at' => $alert->seen_at ?? now()])->save();

        return response()->json(['ok' => true]);
    }

    public function markAllSeen(): JsonResponse
    {
        UsageAlert::whereNull('seen_at')->update(['seen_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Reintenta el envio de una alerta (correo/WhatsApp). */
    public function resend(UsageAlert $alert, UsageAlertService $service): JsonResponse
    {
        $alert->forceFill([
            'email_status' => 'pending', 'whatsapp_status' => 'pending', 'notified_at' => null,
        ])->save();
        $service->notify($alert);

        return response()->json($this->present($alert->refresh()));
    }

    private function present(UsageAlert $a): array
    {
        return [
            'id' => $a->getRouteKey(),
            'threshold' => $a->threshold,
            'total_bytes' => (int) $a->total_bytes,
            'limit_bytes' => (int) $a->limit_bytes,
            'percent' => $a->limit_bytes > 0 ? round($a->total_bytes * 100 / $a->limit_bytes, 1) : null,
            'period_start' => $a->period_start->toDateString(),
            'email_status' => $a->email_status,
            'whatsapp_status' => $a->whatsapp_status,
            'notify_error' => $a->notify_error,
            'seen' => $a->seen_at !== null,
            'created_at' => $a->created_at?->toIso8601String(),
            'device' => $a->relationLoaded('device') && $a->device ? [
                'id' => $a->device->getRouteKey(),
                'unit_number' => $a->device->unit_number,
                'imei' => $a->device->imei,
                'label' => $a->device->label,
            ] : null,
        ];
    }
}
