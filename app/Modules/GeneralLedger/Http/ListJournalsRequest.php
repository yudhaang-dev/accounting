<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Contracts\JournalSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListJournalsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.account_id' => ['sometimes', 'ulid'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'filter.source' => ['sometimes', Rule::enum(JournalSource::class)],
            'filter.transaction_type_key' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
