<?php

namespace App\Exports;

use App\Models\Device;
use App\Support\BillingCycle;
use App\Support\UsageBand;
use App\Services\UsageAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consumo del periodo por unidad frente al paquete de datos, con su categoria (25/50/75/90/100 %).
 * Con el filtro "band" exporta solo una categoria; sin el, toda la lista.
 */
class PlanDataset implements ExportDataset
{
    private BillingCycle $cycle;

    /** @param array<string, mixed> $filters */
    public function __construct(private readonly array $filters, CarbonImmutable $reference)
    {
        $this->cycle = BillingCycle::containing($reference);
    }

    public function title(): string
    {
        return 'Consumo por unidad vs paquete de datos';
    }

    public function description(): string
    {
        $band = $this->filters['band'] ?? null;
        $cat = ($band !== null && $band !== '') ? ' · Categoría: '.UsageBand::label((int) $band) : ' · Todas las categorías';

        return 'Periodo '.$this->cycle->label().$cat;
    }

    public function headings(): array
    {
        return ['Unidad', 'IMEI', 'Consumo (GB)', 'Paquete (GB)', '% del paquete', 'Categoría', 'Último reporte'];
    }

    private function query(): Builder
    {
        $q = Device::query()
            ->withCycleBytes($this->cycle->key())
            ->filter($this->filters);

        return UsageBand::apply($q, $this->filters['band'] ?? null)
            ->orderByDesc('cycle_bytes')
            ->orderBy('devices.unit_number')
            ->orderBy('devices.id');
    }

    public function count(): int
    {
        return UsageBand::apply(Device::query()->withCycleBytes($this->cycle->key())->filter($this->filters), $this->filters['band'] ?? null)->count();
    }

    public function rows(): iterable
    {
        $gb = 1024 * 1024 * 1024;
        $plan = round(UsageAlertService::limitBytes() / $gb, 2);

        foreach ($this->query()->cursor() as $d) {
            $bytes = (int) $d->cycle_bytes;

            yield [
                $d->unit_number,
                $d->imei,
                round($bytes / $gb, 2),
                $plan,
                UsageBand::percent($bytes),
                UsageBand::label(UsageBand::of($bytes)),
                $d->last_day,
            ];
        }
    }
}
