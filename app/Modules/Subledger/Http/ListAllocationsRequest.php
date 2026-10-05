<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAllocationsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.open_item_id' => ['sometimes', 'string', 'size:26'],
            'filter.document_id' => ['sometimes', 'string', 'size:26'],
            'filter.status' => ['sometimes', Rule::in(['active', 'cancelled'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
