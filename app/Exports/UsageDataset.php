<?php

namespace App\Exports;

use App\Support\BillingCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Consumo diario por equipo (consumo del dia y acumulado del periodo). */
class UsageDataset implements ExportDataset
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $range  rango local [desde, hasta)
     */
    public function __construct(private readonly array $filters, private readonly ?array $range) {}

    public function title(): string
    {
        return 'Consumo de datos móviles';
    }

    public function description(): string
    {
        if ($this->range === null) {
            return 'Todo el historial';
        }

        return 'Del '.$this->range[0]->format('Y-m-d').' al '.$this->range[1]->subDay()->format('Y-m-d');
    }

    public function headings(): array
    {
        return ['Día', 'Periodo', 'Unidad', 'IMEI', 'Consumo del día (MB)', 'Acumulado del periodo (MB)'];
    }

    private function query()
    {
        // El consumo del dia se calcula sobre TODO el historial del equipo y despues se recorta al
        // rango, para que el primer dia del rango no aparezca como si hubiera consumido el acumulado.
        $inner = DB::table('data_usage_daily as u')
            ->join('devices as d', 'd.id', '=', 'u.device_id')
            ->selectRaw('u.day, u.period_start, d.unit_number, d.imei, d.id AS device_id, d.label, u.total_bytes,
                u.total_bytes - COALESCE(LAG(u.total_bytes) OVER (PARTITION BY u.device_id, u.period_start ORDER BY u.day), 0) AS delta');

        $query = DB::table($inner, 't')->select('t.*');

        if ($this->range !== null) {
            $query->where('t.day', '>=', $this->range[0]->toDateString())
                ->where('t.day', '<', $this->range[1]->toDateString());
        }
        if (! empty($this->filters['unit'])) {
            $query->where('t.unit_number', $this->filters['unit']);
        }
        if (! empty($this->filters['q'])) {
            $like = \App\Support\Sql::like((string) $this->filters['q']);
            $query->where(function ($w) use ($like) {
                $w->whereRaw("LOWER(COALESCE(t.imei, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(t.unit_number, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(t.label, '')) LIKE ? ESCAPE '\\'", [$like]);
            });
        }
        if (! empty($this->filters['device'])) {
            $id = \App\Support\Hid::decode(\App\Models\Device::HASH_SCOPE, (string) $this->filters['device']);
            $query->where('t.device_id', $id ?? 0);
        }

        return $query->orderBy('t.day')->orderBy('t.unit_number')->orderBy('t.device_id');
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function rows(): iterable
    {
        $mb = (int) config('telpo.bytes_per_mb', 1048576);

        foreach ($this->query()->cursor() as $r) {
            yield [
                (string) $r->day,
                (string) $r->period_start,
                $r->unit_number,
                $r->imei,
                round(max(0, (int) $r->delta) / $mb, 2),
                round(((int) $r->total_bytes) / $mb, 2),
            ];
        }
    }
}
