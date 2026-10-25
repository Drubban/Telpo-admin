<?php

namespace App\Support;

/** Protege los exportes contra inyeccion de formulas (=, +, -, @) al abrirlos en Excel. */
final class Cell
{
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @param array<int, mixed> $row */
    public static function safeRow(array $row): array
    {
        return array_map([self::class, 'safe'], $row);
    }
}
