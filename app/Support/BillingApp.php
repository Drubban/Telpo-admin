<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Device;

/**
 * Version objetivo de la app de cobro. Si el administrador no fija una a mano, la objetivo es la
 * mas alta que reporta cualquier equipo (asi "desactualizada" significa "hay otra mas nueva en la flota").
 */
final class BillingApp
{
    public const KEY_CODE = 'billing_target_code';

    public const KEY_NAME = 'billing_target_name';

    public static function package(): string
    {
        return (string) config('telpo.billing_app_package');
    }

    /** @return array{code: ?int, name: ?string, source: 'manual'|'auto'|'none'} */
    public static function target(): array
    {
        $manual = AppSetting::read(self::KEY_CODE);
        if ($manual !== null && ctype_digit($manual)) {
            return ['code' => (int) $manual, 'name' => AppSetting::read(self::KEY_NAME), 'source' => 'manual'];
        }

        $best = Device::query()
            ->where('billing_app_installed', true)
            ->whereNotNull('billing_app_version_code')
            ->orderByDesc('billing_app_version_code')
            ->first(['billing_app_version', 'billing_app_version_code']);

        return $best
            ? ['code' => (int) $best->billing_app_version_code, 'name' => $best->billing_app_version, 'source' => 'auto']
            : ['code' => null, 'name' => null, 'source' => 'none'];
    }

    /** current | outdated | missing | unknown para un equipo. */
    public static function stateOf(Device $device, ?int $targetCode): string
    {
        if ($device->billing_app_checked_at === null) {
            return 'unknown';
        }
        if (! $device->billing_app_installed) {
            return 'missing';
        }
        $code = $device->billing_app_version_code;

        return ($code !== null && $code >= ($targetCode ?? 0)) ? 'current' : 'outdated';
    }
}
