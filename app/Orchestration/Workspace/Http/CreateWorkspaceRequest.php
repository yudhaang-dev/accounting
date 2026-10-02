<?php

namespace App\Orchestration\Workspace\Http;

use App\Modules\MasterData\Contracts\Currencies;
use Closure;
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
            // Must be an ISO 4217 currency (MD-FR-06).
            'functional_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', fn (string $attribute, mixed $value, Closure $fail) => app(Currencies::class)->isKnown((string) $value) || $fail(__('currencies.unknown', ['code' => (string) $value]))],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'default_locale' => ['nullable', 'string', Rule::in(config('localization.supported'))],
        ];
    }
}
