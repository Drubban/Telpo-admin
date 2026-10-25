<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Periodo de consumo de 1 mes. Inicia el dia de corte (por defecto 20) a las 00:00 hora local y
 * termina justo antes del siguiente corte. $end es EXCLUSIVO (00:00 del siguiente corte).
 * Ejemplo con corte 20: 20 oct 2026 00:00 -> 20 nov 2026 00:00 (se muestra "20 oct - 19 nov").
 */
final class BillingCycle
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public static function cutoffDay(): int
    {
        // Maximo 28 para que el dia exista en todos los meses.
        return max(1, min(28, (int) config('telpo.billing_cutoff_day', 20)));
    }

    public static function timezone(): string
    {
        return (string) config('telpo.timezone', 'America/Mexico_City');
    }

    /** Periodo que contiene la fecha dada (por defecto, ahora). */
    public static function containing(CarbonInterface|string|null $at = null): self
    {
        $tz = self::timezone();
        $local = $at === null
            ? CarbonImmutable::now($tz)
            : CarbonImmutable::parse($at, $tz)->setTimezone($tz);

        $start = $local->startOfDay()->day(self::cutoffDay());
        if ($start->greaterThan($local)) {
            $start = $start->subMonthNoOverflow();
        }

        return new self($start, $start->addMonthNoOverflow());
    }

    /** @return list<self> Periodos mas recientes primero, empezando por el actual. */
    public static function recent(int $count = 6): array
    {
        $cycles = [];
        $cycle = self::containing();
        for ($i = 0; $i < $count; $i++) {
            $cycles[] = $cycle;
            $cycle = self::containing($cycle->start->subDay());
        }

        return $cycles;
    }

    /** Clave estable del periodo (fecha local de inicio, Y-m-d). */
    public function key(): string
    {
        return $this->start->toDateString();
    }

    public function lastDay(): CarbonImmutable
    {
        return $this->end->subDay();
    }

    public function label(): string
    {
        return $this->start->locale('es')->isoFormat('D MMM YYYY')
            .' - '.$this->lastDay()->locale('es')->isoFormat('D MMM YYYY');
    }

    /** Dias transcurridos del periodo (minimo 1, maximo la duracion del periodo). */
    public function elapsedDays(): int
    {
        $now = CarbonImmutable::now(self::timezone());
        $end = $now->lessThan($this->end) ? $now : $this->end;
        $days = (int) $this->start->startOfDay()->diffInDays($end->startOfDay()) + 1;

        return max(1, min($days, (int) $this->start->diffInDays($this->end)));
    }

    /** @return array{key: string, label: string, start: string, end: string, is_current: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'start' => $this->start->toDateString(),
            'end' => $this->lastDay()->toDateString(),
            'is_current' => $this->key() === self::containing()->key(),
        ];
    }
}
