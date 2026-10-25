<?php

namespace App\Models;

use App\Models\Concerns\HasHashedRouteKey;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Device extends Model
{
    use HasHashedRouteKey;

    public const HASH_SCOPE = 'device';

    public const STATUSES = ['active', 'inactive', 'released'];

    // token_hash, install_id y status no son asignables en masa: solo el codigo los define.
    protected $fillable = ['imei', 'unit_number', 'label', 'serial', 'model', 'app_version'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'nfc_enabled' => 'boolean',
            'camera_ok' => 'boolean',
            'is_device_owner' => 'boolean',
            'unit_updated_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'bump_stats_at' => 'datetime',
            'billing_app_installed' => 'boolean',
            'billing_app_checked_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    public function usageDays(): HasMany
    {
        return $this->hasMany(DataUsageDaily::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes((int) config('telpo.online_minutes', 30)));
    }

    /** Agrega cycle_bytes: consumo acumulado del periodo (0 si no ha reportado). */
    public function scopeWithCycleBytes(Builder $query, string $periodStart): Builder
    {
        $totals = DB::table('data_usage_daily')
            ->select('device_id', DB::raw('MAX(total_bytes) AS cycle_bytes'), DB::raw('MAX(day) AS last_day'))
            ->where('period_start', $periodStart)
            ->groupBy('device_id');

        return $query
            ->leftJoinSub($totals, 'usage_totals', 'usage_totals.device_id', '=', 'devices.id')
            ->select('devices.*', DB::raw('COALESCE(usage_totals.cycle_bytes, 0) AS cycle_bytes'), 'usage_totals.last_day');
    }

    /**
     * Estado de la app de cobro frente a la version objetivo ($targetCode):
     * current (al dia), outdated (desactualizada), missing (no instalada), unknown (aun sin reporte).
     */
    public function scopeBillingState(Builder $query, string $state, ?int $targetCode): Builder
    {
        return match ($state) {
            'unknown' => $query->whereNull('devices.billing_app_checked_at'),
            'missing' => $query->where('devices.billing_app_installed', false),
            'outdated' => $query->where('devices.billing_app_installed', true)
                ->where(fn ($w) => $w->whereNull('devices.billing_app_version_code')
                    ->orWhere('devices.billing_app_version_code', '<', $targetCode ?? 0)),
            'current' => $query->where('devices.billing_app_installed', true)
                ->where('devices.billing_app_version_code', '>=', $targetCode ?? 0),
            default => $query,
        };
    }

    /**
     * Filtros comunes de tablas y exportes. Todo va con parametros; el texto libre se escapa para
     * LIKE y las columnas de orden salen de una lista cerrada.
     *
     * @param  array<string, mixed>  $f
     */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        if (! empty($f['q'])) {
            $like = Sql::like((string) $f['q']);
            $query->where(function (Builder $w) use ($like) {
                $w->whereRaw("LOWER(COALESCE(devices.imei, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(devices.unit_number, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(devices.label, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(devices.serial, '')) LIKE ? ESCAPE '\\'", [$like]);
            });
        }

        if (! empty($f['status'])) {
            $query->where('devices.status', $f['status']);
        }

        if (! empty($f['unit'])) {
            $query->where('devices.unit_number', $f['unit']);
        }

        if (($f['online'] ?? null) === 'yes') {
            $query->where('devices.last_seen_at', '>', now()->subMinutes((int) config('telpo.online_minutes', 30)));
        } elseif (($f['online'] ?? null) === 'no') {
            $query->where(function (Builder $w) {
                $w->whereNull('devices.last_seen_at')
                    ->orWhere('devices.last_seen_at', '<=', now()->subMinutes((int) config('telpo.online_minutes', 30)));
            });
        }

        if (($f['nfc'] ?? null) === 'problem') {
            $query->where(function (Builder $w) {
                $w->where('devices.nfc_enabled', false)->orWhere('devices.camera_ok', false);
            });
        }

        return $query;
    }
}
