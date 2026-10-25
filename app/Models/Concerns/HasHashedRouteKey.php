<?php

namespace App\Models\Concerns;

use App\Support\Hid;

/** Las rutas usan un hash en lugar del ID numerico (/devices/Xk9aB2... en vez de /devices/7). */
trait HasHashedRouteKey
{
    public function getRouteKey(): string
    {
        return Hid::encode(static::HASH_SCOPE, (int) $this->getKey());
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $id = Hid::decode(static::HASH_SCOPE, is_string($value) ? $value : null);

        return $id === null ? null : $this->newQuery()->whereKey($id)->first();
    }
}
