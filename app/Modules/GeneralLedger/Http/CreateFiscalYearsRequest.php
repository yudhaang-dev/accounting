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
            // Required for the first year (the 1st of the calendar start month); later years continue
            // the previous one, and a start date, when given, must equal that continuation.
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'count' => ['sometimes', 'integer', 'between:1,5'],
            // Only with count 1; defaults to FY2026, or FY2026/2027 when the year does not start in January.
            'name' => ['sometimes', 'string', 'max:32'],
        ];
    }
}
