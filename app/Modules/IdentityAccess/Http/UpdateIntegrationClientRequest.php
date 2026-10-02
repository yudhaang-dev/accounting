<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\IntegrationScopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntegrationClientRequest extends FormRequest
{
    /**
     * Each list replaces the previous one. A scope removed here stops working at once, also on
     * tokens already issued. `source_system` cannot be changed.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:100'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(IntegrationScopes::all())],
            'transaction_type_keys' => ['sometimes', 'array', 'max:200'],
            'transaction_type_keys.*' => ['string', 'distinct', 'regex:'.CreateIntegrationClientRequest::TYPE_KEY],
        ];
    }
}
