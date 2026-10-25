<?php

namespace App\Support;

/** Calculos geograficos simples (sin dependencias). */
final class Geo
{
    private const EARTH_RADIUS_M = 6371000.0;

    /** Distancia en metros entre dos puntos (haversine). */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** ¿El punto cae dentro de la zona de operacion configurada (por defecto, Mexico)? */
    public static function inBounds(float $lat, float $lng): bool
    {
        $b = (array) config('telpo.geo_bounds');

        return $lat >= $b['lat_min'] && $lat <= $b['lat_max'] && $lng >= $b['lng_min'] && $lng <= $b['lng_max'];
    }
}
