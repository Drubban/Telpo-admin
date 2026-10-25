<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el sobre de la sincronizacion. Cada evento se valida despues, uno por uno, en
 * DeviceSyncService: un evento malo se descarta sin tumbar el lote completo.
 */
class SyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'imei' => ['nullable', 'regex:/^\d{14,16}$/'],
            'unit_number' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pN _.\-\/#]+$/u'],
            'unit_updated_at_ms' => ['nullable', 'integer', 'min:0'],
            'serial' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'nfc_enabled' => ['nullable', 'boolean'],
            'camera_ok' => ['nullable', 'boolean'],
            'is_device_owner' => ['nullable', 'boolean'],
            'bumps_version' => ['nullable', 'string', 'max:40'],
            'bump_stats' => ['nullable', 'array'],
            'bump_stats.count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'bump_stats.alerts_today' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'bump_stats.gps_status' => ['nullable', 'in:ok,gps_off,no_permission,no_fix,no_bumps,not_running'],
            'billing_app' => ['nullable', 'array'],
            'billing_app.package' => ['nullable', 'string', 'max:120'],
            'billing_app.installed' => ['nullable', 'boolean'],
            'billing_app.version_name' => ['nullable', 'string', 'max:40'],
            'billing_app.version_code' => ['nullable', 'integer', 'min:0'],
            'credential_versions' => ['nullable', 'array'],
            'credential_versions.*' => ['integer', 'min:0'],
            'events' => ['nullable', 'array', 'max:200'],
            'usage' => ['nullable', 'array'],
            'usage.period_start_ms' => ['required_with:usage', 'integer', 'min:1'],
            'usage.rx_bytes' => ['required_with:usage', 'integer', 'min:0'],
            'usage.tx_bytes' => ['required_with:usage', 'integer', 'min:0'],
            'usage.sampled_at_ms' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
