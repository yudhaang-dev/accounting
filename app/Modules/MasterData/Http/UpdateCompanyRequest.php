<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Only while the company is in setup (MD-AC-05); checked against currency reference data in M2-03.
            'functional_currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'default_locale' => ['sometimes', 'string', Rule::in(config('localization.supported'))],
        ];
    }
}
