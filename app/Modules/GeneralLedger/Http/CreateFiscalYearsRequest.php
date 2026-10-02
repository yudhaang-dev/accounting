<?php

namespace App\Modules\GeneralLedger\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateFiscalYearsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Required for the first year (the 1st of the start month of a monthly calendar, any date for
            // 4-4-5; a free calendar takes it from its first period). Later years continue the previous
            // one, and a start date, when given, must equal that continuation.
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'count' => ['sometimes', 'integer', 'between:1,5'],
            // Only with count 1; defaults to FY2026, or FY2026/2027 when the year does not start in January.
            'name' => ['sometimes', 'string', 'max:32'],
            // 4-4-5 only: a 53-week year; the extra week goes to the last period. Only with count 1.
            'extra_week' => ['sometimes', 'boolean'],
            // Free calendar only, and then required: the periods of the one year, in order and without gaps.
            'periods' => ['sometimes', 'array', 'min:1', 'max:60'],
            'periods.*.name' => ['sometimes', 'nullable', 'string', 'max:32'],
            'periods.*.start_date' => ['required', 'date_format:Y-m-d'],
            'periods.*.end_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
