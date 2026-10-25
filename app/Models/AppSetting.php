<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ajustes clave/valor editables desde la web. */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function read(string $key, ?string $default = null): ?string
    {
        return static::query()->whereKey($key)->value('value') ?? $default;
    }

    public static function write(string $key, ?string $value): void
    {
        if ($value === null) {
            static::query()->whereKey($key)->delete();

            return;
        }
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
