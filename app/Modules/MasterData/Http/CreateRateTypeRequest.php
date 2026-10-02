<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateRateTypeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Stable identifier used in API payloads and rate lookups.
            'key' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:255'],
            // Rates older than this many days are not used for a date; empty means no limit (MD-FR-08).
            'max_age_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
