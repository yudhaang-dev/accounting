<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Contracts\DocumentKind;
use App\Modules\Subledger\Domain\OpenItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOpenItemsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.party_id' => ['sometimes', 'string', 'size:26'],
            'filter.control_role' => ['sometimes', Rule::enum(ControlRole::class)],
            'filter.kind' => ['sometimes', Rule::enum(DocumentKind::class)],
            'filter.status' => ['sometimes', Rule::enum(OpenItemStatus::class)],
            'filter.due_to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
