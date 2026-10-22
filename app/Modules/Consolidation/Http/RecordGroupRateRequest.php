<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class RecordGroupRateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rate_type_id' => ['required', 'string', 'ulid'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            // Up to 12 integer and 12 decimal digits, greater than zero, as a decimal string (API-TR-05).
            'rate' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,12})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ];
    }
}
