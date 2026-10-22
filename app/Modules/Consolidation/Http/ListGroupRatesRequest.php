<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class ListGroupRatesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter.rate_type_id' => ['sometimes', 'string', 'ulid'],
            'filter.currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'filter.include_superseded' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
