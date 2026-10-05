<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReconciliationsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.as_of' => ['sometimes', 'date_format:Y-m-d'],
            'filter.account_id' => ['sometimes', 'string', 'size:26'],
            'filter.status' => ['sometimes', Rule::in(['balanced', 'difference', 'waived'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
