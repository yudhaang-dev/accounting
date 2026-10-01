<?php

namespace App\Orchestration\Workspace\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWorkspaceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workspace_name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            // Checked against the currency reference data once it exists (M2-03).
            'functional_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'default_locale' => ['nullable', 'string', Rule::in(config('localization.supported'))],
        ];
    }
}
