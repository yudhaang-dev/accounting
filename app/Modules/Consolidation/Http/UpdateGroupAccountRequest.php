<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGroupAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // Null clears it. Runs made earlier keep the class their accounts had.
            'cash_flow_class' => ['sometimes', 'nullable', 'string', 'in:cash,operating,investing,financing'],
        ];
    }
}
