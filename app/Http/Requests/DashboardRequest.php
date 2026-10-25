<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'cycle' => ['nullable', 'date_format:Y-m-d'],
            'device' => ['nullable', 'string', 'max:40'],
        ];
    }
}
