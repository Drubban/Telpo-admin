<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** Registra una accion de la web; nunca debe romper la peticion que la origino. */
    public static function record(string $action, ?int $userId, ?string $target = null, array $meta = [], ?string $ip = null): void
    {
        try {
            static::create([
                'action' => $action,
                'user_id' => $userId,
                'target' => $target,
                'meta' => $meta ?: null,
                'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
