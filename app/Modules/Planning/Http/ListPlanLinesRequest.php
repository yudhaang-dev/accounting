<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class ListPlanLinesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.period_id' => ['sometimes', 'string', 'size:26'],
            'filter.account_id' => ['sometimes', 'string', 'size:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
