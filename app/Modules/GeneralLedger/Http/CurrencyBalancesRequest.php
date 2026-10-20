<?php

namespace App\Modules\GeneralLedger\Http;

use Illuminate\Foundation\Http\FormRequest;

class CurrencyBalancesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }
}
