<?php

namespace App\Models;

use App\Models\Concerns\HasHashedRouteKey;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SpeedBump extends Model
{
    use HasHashedRouteKey;

    public const HASH_SCOPE = 'bump';

    protected $fillable = ['name', 'latitude', 'longitude', 'radius_m', 'heading_deg', 'active', 'notes'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'integer',
            'heading_deg' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function routes(): BelongsToMany
    {
        return $this->belongsToMany(BumpRoute::class, 'bump_route_speed_bump');
    }

    /**
     * Filtros de la tabla. Todo va con parametros; el texto libre se escapa para LIKE.
     *
     * @param  array<string, mixed>  $f
     */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        if (! empty($f['q'])) {
            $like = Sql::like((string) $f['q']);
            $query->where(function (Builder $w) use ($like) {
                $w->whereRaw("LOWER(speed_bumps.name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(speed_bumps.notes, '')) LIKE ? ESCAPE '\\'", [$like]);
            });
        }

        if (($f['active'] ?? null) === '1') {
            $query->where('speed_bumps.active', true);
        } elseif (($f['active'] ?? null) === '0') {
            $query->where('speed_bumps.active', false);
        }

        if (! empty($f['route_id'])) {
            $routeId = (int) $f['route_id'];
            $query->whereExists(function ($sub) use ($routeId) {
                $sub->selectRaw('1')->from('bump_route_speed_bump as brs')
                    ->whereColumn('brs.speed_bump_id', 'speed_bumps.id')
                    ->where('brs.bump_route_id', $routeId);
            });
        }

        return $query;
    }
}
