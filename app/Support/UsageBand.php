<?php

namespace App\Support;

use App\Services\UsageAlertService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Categorias de consumo respecto al paquete de datos. Cada categoria es el umbral mas alto que el
 * equipo ya supero (25, 50, 75, 90, 100 por defecto); "0" es "por debajo del primer umbral".
 * Se usa en la tabla de consumo (colores), en el filtro y en el export.
 */
final class UsageBand
{
    /** @return list<int> umbrales ordenados */
    public static function thresholds(): array
    {
        $t = array_values(array_unique(array_map('intval', (array) config('telpo.alert_thresholds'))));
        sort($t);

        return $t;
    }

    /** @return list<string> valores validos del filtro "band" */
    public static function keys(): array
    {
        return array_merge(['0'], array_map('strval', self::thresholds()));
    }

    /** Categoria de un consumo en bytes: 0 o el umbral mas alto superado. */
    public static function of(int $bytes): int
    {
        $limit = UsageAlertService::limitBytes();
        $band = 0;
        if ($limit > 0) {
            foreach (self::thresholds() as $t) {
                if ($bytes * 100 >= $t * $limit) {
                    $band = $t;
                }
            }
        }

        return $band;
    }

    public static function percent(int $bytes): ?float
    {
        $limit = UsageAlertService::limitBytes();

        return $limit > 0 ? round($bytes * 100 / $limit, 1) : null;
    }

    public static function label(int $band): string
    {
        $first = self::thresholds()[0] ?? 25;
        $last = self::thresholds() === [] ? 100 : max(self::thresholds());

        return match (true) {
            $band === 0 => "Normal (menos de {$first} %)",
            $band >= 100 && $band === $last => "{$band} % o más (paquete excedido)",
            default => "{$band} % o más",
        };
    }

    /**
     * Filtra equipos por categoria. Requiere haber llamado antes a withCycleBytes() (usa su
     * subconsulta usage_totals). Usa enteros: bytes * 100 >= umbral * limite.
     */
    public static function apply(Builder $query, ?string $band): Builder
    {
        if ($band === null || $band === '' || ! in_array($band, self::keys(), true)) {
            return $query;
        }
        $limit = UsageAlertService::limitBytes();
        $ts = self::thresholds();
        $col = 'COALESCE(usage_totals.cycle_bytes, 0) * 100';
        $b = (int) $band;

        if ($b === 0) {
            return $query->whereRaw("{$col} < ?", [($ts[0] ?? 0) * $limit]);
        }
        $query->whereRaw("{$col} >= ?", [$b * $limit]);
        $next = null;
        foreach ($ts as $t) {
            if ($t > $b) {
                $next = $t;
                break;
            }
        }

        return $next === null ? $query : $query->whereRaw("{$col} < ?", [$next * $limit]);
    }
}
