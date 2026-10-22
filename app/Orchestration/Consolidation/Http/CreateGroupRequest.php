<?php

namespace App\Orchestration\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_company_id' => ['required', 'string', 'ulid'],
            // ISO 4217; the results of the group are stated in it and it does not change afterwards.
            'presentation_currency' => ['required', 'string', 'size:3'],
        ];
    }
}
