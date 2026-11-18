<?php

namespace App\Http\Resources;

use App\Support\UsageBand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Device */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getRouteKey(),
            'imei' => $this->imei,
            'unit_number' => $this->unit_number,
            'label' => $this->label,
            'serial' => $this->serial,
            'phone_number' => $this->phone_number,
            'phone_source' => $this->phone_source,
            'sim_iccid' => $this->sim_iccid,
            'usage_breakdown' => $this->usage_breakdown === null ? null : [
                'period_start_ms' => $this->usage_breakdown['period_start_ms'] ?? null,
                'apps' => $this->usage_breakdown['apps'] ?? [],
                'reported_at' => $this->usage_breakdown_at?->toIso8601String(),
            ],
            'model' => $this->model,
            'signal' => [
                'mobile' => $this->signal_mobile,
                'mobile_reason' => $this->signal_mobile_reason,
                'wifi' => $this->signal_wifi,
                'wifi_reason' => $this->signal_wifi_reason,
                'checked_at' => $this->signal_checked_at?->toIso8601String(),
            ],
            // La Telpo ya tiene la llave actual y puede validar contrasenas temporales sin conexion.
            'temp_code_ready' => (int) $this->temp_secret_version > 0
                && (int) $this->temp_secret_acked_version >= (int) $this->temp_secret_version,
            'status' => $this->status,
            'app_version' => $this->app_version,
            'nfc_enabled' => $this->nfc_enabled,
            'camera_ok' => $this->camera_ok,
            'is_device_owner' => $this->is_device_owner,
            'online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'unit_updated_at' => $this->unit_updated_at?->toIso8601String(),
            'bumps_version' => $this->bumps_version,
            'bump_count' => $this->bump_count,
            'bump_alerts_today' => $this->bump_alerts_today,
            'gps_status' => $this->gps_status,
            'bump_stats_at' => $this->bump_stats_at?->toIso8601String(),
            'billing_app' => [
                'installed' => $this->billing_app_installed,
                'version' => $this->billing_app_version,
                'version_code' => $this->billing_app_version_code,
                'checked_at' => $this->billing_app_checked_at?->toIso8601String(),
            ],
            'cycle_bytes' => (int) ($this->cycle_bytes ?? 0),
            'plan_percent' => UsageBand::percent((int) ($this->cycle_bytes ?? 0)),
            'band' => UsageBand::of((int) ($this->cycle_bytes ?? 0)),
            'last_report_day' => $this->last_day ?? null,
        ];
    }
}
