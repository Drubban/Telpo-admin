<?php

namespace App\Exports;

use App\Models\Device;
use App\Support\BillingCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Padron de Telpo con su unidad, estado y consumo del periodo de la fecha de referencia. */
class DevicesDataset implements ExportDataset
{
    private BillingCycle $cycle;

    /** @param array<string, mixed> $filters */
    public function __construct(private readonly array $filters, CarbonImmutable $reference)
    {
        $this->cycle = BillingCycle::containing($reference);
    }

    public function title(): string
    {
        return 'Equipos Telpo T20';
    }

    public function description(): string
    {
        return 'Consumo del periodo '.$this->cycle->label();
    }

    public function headings(): array
    {
        return ['Unidad', 'IMEI', 'Etiqueta', 'Serie', 'Estado', 'En línea', 'Última conexión', 'NFC', 'Cámara/QR', 'Versión app', 'App de cobro', 'Consumo periodo (MB)'];
    }

    private function query(): Builder
    {
        return Device::query()
            ->withCycleBytes($this->cycle->key())
            ->filter($this->filters)
            ->orderBy('devices.unit_number')
            ->orderBy('devices.id');
    }

    public function count(): int
    {
        return Device::query()->filter($this->filters)->count();
    }

    public function rows(): iterable
    {
        $mb = (int) config('telpo.bytes_per_mb', 1048576);
        $tz = BillingCycle::timezone();

        foreach ($this->query()->cursor() as $d) {
            yield [
                $d->unit_number,
                $d->imei,
                $d->label,
                $d->serial,
                ['active' => 'Activo', 'inactive' => 'Inactivo', 'released' => 'Liberado'][$d->status] ?? $d->status,
                $d->isOnline() ? 'Sí' : 'No',
                $d->last_seen_at?->setTimezone($tz)->format('Y-m-d H:i'),
                $d->nfc_enabled === null ? '' : ($d->nfc_enabled ? 'Activo' : 'Apagado'),
                $d->camera_ok === null ? '' : ($d->camera_ok ? 'Disponible' : 'Bloqueada'),
                $d->app_version,
                $d->billing_app_checked_at === null ? '' : ($d->billing_app_installed ? ($d->billing_app_version ?: 'Instalada') : 'No instalada'),
                round(((int) $d->cycle_bytes) / $mb, 2),
            ];
        }
    }
}
