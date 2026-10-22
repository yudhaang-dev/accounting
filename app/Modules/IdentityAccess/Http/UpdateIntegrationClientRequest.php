<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\IntegrationScopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntegrationClientRequest extends FormRequest
{
    /**
     * Each list replaces the previous one. A scope removed here stops working at once, also on
     * tokens already issued. `source_system` cannot be changed. `data_scope` replaces the dimension values
     * the client is limited to (IA-FR-03); an empty list removes the limit. `webhook_url` sets where its
     * webhooks go (API-TR-25); null stops them. Only a user without a limit may change a client.
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
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:500', new WebhookUrl],
            'data_scope' => ['sometimes', 'array', 'max:200'],
            'data_scope.*' => ['string', 'distinct'],
        ];
    }
}
