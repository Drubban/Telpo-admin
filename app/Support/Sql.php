<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Fragmentos SQL que dependen del motor. En produccion el motor es PostgreSQL. */
final class Sql
{
    /**
     * Expresion que convierte una columna timestamp (guardada en UTC) en la fecha local.
     * $column es siempre un nombre de columna escrito en el codigo, nunca texto del usuario.
     */
    public static function localDay(string $column): string
    {
        if (! preg_match('/^[a-z_\.]+$/', $column)) {
            throw new \InvalidArgumentException('Nombre de columna no valido');
        }

        $tz = BillingCycle::timezone();
        if (! preg_match('#^[A-Za-z_/\-+0-9]+$#', $tz)) {
            throw new \InvalidArgumentException('Zona horaria no valida');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            return "((({$column}) AT TIME ZONE 'UTC') AT TIME ZONE '{$tz}')::date";
        }

        return "DATE({$column})";
    }

    /** Escapa % _ y \ para usar el texto del usuario dentro de LIKE. */
    public static function like(string $text): string
    {
        return '%'.addcslashes(mb_strtolower($text), '\\%_').'%';
    }
}
