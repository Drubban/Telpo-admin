<?php

namespace App\Models;

use App\Models\Concerns\HasHashedRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BumpRoute extends Model
{
    use HasHashedRouteKey;

    public const HASH_SCOPE = 'bump_route';

    protected $fillable = ['name'];

    public function bumps(): BelongsToMany
    {
        return $this->belongsToMany(SpeedBump::class, 'bump_route_speed_bump');
    }
}
