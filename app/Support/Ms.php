<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Milisegundos desde epoch (el formato de tiempo que usa la app Android). */
final class Ms
{
    public static function of(?CarbonInterface $time): int
    {
        return $time === null ? 0 : (int) floor($time->getPreciseTimestamp(3));
    }
}
