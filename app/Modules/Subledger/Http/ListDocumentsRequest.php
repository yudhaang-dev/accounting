<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Contracts\DocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDocumentsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.party_id' => ['sometimes', 'string', 'size:26'],
            'filter.kind' => ['sometimes', Rule::enum(DocumentKind::class)],
            'filter.date_from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.date_to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
