<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class SaveTranslationPolicyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The version the client read; 0 when the group has no policy yet.
            'lock_version' => ['required', 'integer', 'min:0'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            // Assets and liabilities.
            'closing_rate_type_id' => ['required', 'string', 'ulid'],
            // Income and expenses.
            'average_rate_type_id' => ['required', 'string', 'ulid'],
            // Equity.
            'historical_rate_type_id' => ['required', 'string', 'ulid'],
            // The equity account of the group chart that takes the translation difference.
            'difference_account_id' => ['required', 'string', 'ulid'],
        ];
    }
}
