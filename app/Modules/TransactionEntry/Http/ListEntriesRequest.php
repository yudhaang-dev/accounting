<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\EntryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEntriesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::enum(EntryStatus::class)],
            'filter.transaction_type_key' => ['sometimes', 'string', 'max:64'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'sort' => ['sometimes', Rule::in(['accounting_date', '-accounting_date', 'number', '-number'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
