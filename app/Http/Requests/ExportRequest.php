<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use App\Support\BillingCycle;
use App\Support\UsageBand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRequest extends FormRequest
{
    public const SCOPES = ['day', 'week', 'month', 'cycle', 'all'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'dataset' => ['required', Rule::in(['devices', 'events', 'usage', 'plan'])],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'scope' => ['nullable', Rule::in(self::SCOPES)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            // Mismos filtros que las tablas.
            'q' => ['nullable', 'string', 'max:60'],
            'device' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', Rule::enum(EventType::class)],
            'success' => ['nullable', Rule::in(['0', '1'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'released'])],
            'unit' => ['nullable', 'string', 'max:40'],
            'band' => ['nullable', Rule::in(UsageBand::keys())],
        ];
    }

    public function scope(): string
    {
        return $this->validated('scope') ?? 'all';
    }

    /** Fecha de referencia en hora local (la del exporte, o hoy). */
    public function referenceDate(): CarbonImmutable
    {
        $tz = BillingCycle::timezone();
        $date = $this->validated('date');

        return $date ? CarbonImmutable::parse($date, $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfDay();
    }

    /**
     * Rango local [desde, hasta) segun el alcance, o null si es "todo".
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function range(): ?array
    {
        $d = $this->referenceDate();

        return match ($this->scope()) {
            'day' => [$d, $d->addDay()],
            'week' => [$d->startOfWeek(CarbonImmutable::MONDAY), $d->startOfWeek(CarbonImmutable::MONDAY)->addWeek()],
            'month' => [$d->startOfMonth(), $d->startOfMonth()->addMonthNoOverflow()],
            'cycle' => (function () use ($d) {
                $c = BillingCycle::containing($d);

                return [$c->start, $c->end];
            })(),
            default => null,
        };
    }

    /** Nombre base del archivo: EX-T20-AAAA-MM-DD (sin extension). */
    public function fileBase(): string
    {
        return 'EX-T20-'.$this->referenceDate()->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['q', 'device', 'type', 'success', 'status', 'unit', 'band']);
    }
}
