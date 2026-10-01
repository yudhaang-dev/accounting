<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\PeriodStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPeriodsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.fiscal_year_id' => ['sometimes', 'string', 'size:26'],
            'filter.status' => ['sometimes', Rule::enum(PeriodStatus::class)],
            // The period that contains this date.
            'filter.date' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
