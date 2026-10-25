<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\BillingCycle;

class EventIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'device' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', Rule::enum(EventType::class)],
            'success' => ['nullable', Rule::in(['0', '1'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /** Filtros listos para DeviceEvent::filter (fechas locales -> UTC, "to" inclusivo). */
    public function filters(): array
    {
        $f = $this->safe()->only(['q', 'device', 'type', 'success']);
        $tz = BillingCycle::timezone();

        if ($from = $this->validated('from')) {
            $f['from'] = CarbonImmutable::parse($from, $tz)->startOfDay()->utc()->toDateTimeString();
        }
        if ($to = $this->validated('to')) {
            $f['to'] = CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()->utc()->toDateTimeString();
        }

        return $f;
    }
}
