<?php

namespace App\Modules\Reporting\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRoleMappingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'account_ids' => ['required', 'array', 'min:1', 'max:50'],
            'account_ids.*' => ['string', 'ulid'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
