<?php

namespace App\Exports;

use App\Enums\EventType;
use App\Models\DeviceEvent;
use App\Support\BillingCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Bitacora de eventos de las Telpo dentro del rango elegido. */
class EventsDataset implements ExportDataset
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $range  rango local [desde, hasta)
     */
    public function __construct(private readonly array $filters, private readonly ?array $range) {}

    public function title(): string
    {
        return 'Registro de eventos';
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
        return ['Fecha y hora', 'Unidad', 'IMEI', 'Evento', 'Resultado', 'Motivo', 'Detalle'];
    }

    private function query(): Builder
    {
        $filters = $this->filters;
        if ($this->range !== null) {
            $filters['from'] = $this->range[0]->utc()->toDateTimeString();
            $filters['to'] = $this->range[1]->utc()->toDateTimeString();
        }

        return DeviceEvent::query()
            ->with('device:id,unit_number,imei')
            ->filter($filters)
            ->orderBy('device_events.occurred_at')
            ->orderBy('device_events.id');
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function rows(): iterable
    {
        $tz = BillingCycle::timezone();
        $purposes = [
            'open_settings' => 'Abrir Configuración',
            'power_menu' => 'Reiniciar/Apagar',
            'launcher_switch' => 'Cambiar launcher',
            'launcher_release' => 'Liberar equipo',
            'app_config' => 'Configuración de la app',
        ];

        foreach ($this->query()->cursor() as $e) {
            $detail = $e->detail ?? [];
            $purpose = $detail['purpose'] ?? null;
            unset($detail['purpose']);

            yield [
                $e->occurred_at->setTimezone($tz)->format('Y-m-d H:i:s'),
                $e->device?->unit_number,
                $e->device?->imei,
                EventType::tryFrom($e->type)?->label() ?? $e->type,
                $e->success === null ? '' : ($e->success ? 'Exitoso' : 'Fallido'),
                $purpose === null ? '' : ($purposes[$purpose] ?? $purpose),
                $detail === [] ? '' : json_encode($detail, JSON_UNESCAPED_UNICODE),
            ];
        }
    }
}
