<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\IntegrationScopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateIntegrationClientRequest extends FormRequest
{
    public const TYPE_KEY = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /**
     * `source_system` names the calling system and is unique per company; it cannot change later.
     * `scopes` are the OAuth scopes the client may request. `transaction_type_keys` is the allowlist
     * of transaction types it may use; empty allows none. `data_scope` limits the client to dimension
     * values, each standing for itself and its descendants (IA-FR-03); empty means no limit. Only a
     * user without a limit may register a client.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'source_system' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_.-]{1,63}$/'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(IntegrationScopes::all())],
            'transaction_type_keys' => ['sometimes', 'array', 'max:200'],
            'transaction_type_keys.*' => ['string', 'distinct', 'regex:'.self::TYPE_KEY],
            'data_scope' => ['sometimes', 'array', 'max:200'],
            'data_scope.*' => ['string', 'distinct'],
        ];
    }
}
