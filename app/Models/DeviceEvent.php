<?php

namespace App\Models;

use App\Models\Concerns\HasHashedRouteKey;
use App\Support\Hid;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEvent extends Model
{
    use HasHashedRouteKey;

    public const HASH_SCOPE = 'event';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'detail' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @param  array<string, mixed>  $f  device (hash), type, success (0/1), q, from, to (UTC, 'Y-m-d H:i:s', to exclusivo)
     */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        if (! empty($f['device'])) {
            // Un hash invalido no debe devolver "todo": se fuerza un resultado vacio.
            $deviceId = Hid::decode(Device::HASH_SCOPE, (string) $f['device']);
            $query->where('device_events.device_id', $deviceId ?? 0);
        }

        if (! empty($f['type'])) {
            $query->where('device_events.type', $f['type']);
        } else {
            // Los avisos de tope son muchos: salen en el detalle del equipo, no en el registro general.
            $query->where('device_events.type', '!=', 'bump_alert');
        }

        if (isset($f['success']) && $f['success'] !== '') {
            $query->where('device_events.success', (bool) (int) $f['success']);
        }

        if (! empty($f['from'])) {
            $query->where('device_events.occurred_at', '>=', $f['from']);
        }

        if (! empty($f['to'])) {
            $query->where('device_events.occurred_at', '<', $f['to']);
        }

        if (! empty($f['q'])) {
            $like = Sql::like((string) $f['q']);
            $query->whereHas('device', function (Builder $d) use ($like) {
                $d->where(function (Builder $w) use ($like) {
                    $w->whereRaw("LOWER(COALESCE(devices.imei, '')) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(COALESCE(devices.unit_number, '')) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(COALESCE(devices.label, '')) LIKE ? ESCAPE '\\'", [$like]);
                });
            });
        }

        return $query;
    }
}
