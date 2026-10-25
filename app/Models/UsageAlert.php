<?php

namespace App\Models;

use App\Models\Concerns\HasHashedRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageAlert extends Model
{
    use HasHashedRouteKey;

    public const HASH_SCOPE = 'usage_alert';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'silent' => 'boolean',
            'notified_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
