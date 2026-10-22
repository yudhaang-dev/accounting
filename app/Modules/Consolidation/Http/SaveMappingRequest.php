<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class SaveMappingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The version the client read; 0 when the group has no mapping yet.
            'lock_version' => ['required', 'integer', 'min:0'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            // The whole mapping as it should be from now on: rows left out are no longer mapped.
            'mappings' => ['present', 'array', 'max:5000'],
            'mappings.*.company_id' => ['required', 'string', 'ulid'],
            'mappings.*.account_id' => ['required', 'string', 'ulid'],
            'mappings.*.group_account_id' => ['required', 'string', 'ulid'],
        ];
    }
}
