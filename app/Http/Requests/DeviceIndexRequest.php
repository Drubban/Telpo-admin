<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\UsageBand;
use Illuminate\Validation\Rule;

/** Filtros de la tabla de equipos y de consumo. Todo lo que no esta en esta lista se ignora. */
class DeviceIndexRequest extends FormRequest
{
    public const SORTABLE = ['unit_number', 'imei', 'last_seen_at', 'cycle_bytes', 'status', 'enrolled_at'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'released'])],
            'unit' => ['nullable', 'string', 'max:40'],
            'online' => ['nullable', Rule::in(['yes', 'no'])],
            'nfc' => ['nullable', Rule::in(['problem'])],
            'cycle' => ['nullable', 'date_format:Y-m-d'],
            'band' => ['nullable', Rule::in(UsageBand::keys())],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['q', 'status', 'unit', 'online', 'nfc']);
    }
}
