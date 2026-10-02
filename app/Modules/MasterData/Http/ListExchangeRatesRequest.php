<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;

class ListExchangeRatesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.rate_type' => ['sometimes', 'string', 'max:32'],
            'filter.currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'filter.include_superseded' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
