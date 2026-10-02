<?php

namespace App\Modules\IdentityAccess\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembershipRequest extends FormRequest
{
    /**
     * `role_ids` replaces all roles of the member; `expires_at: null` removes the expiry.
     * `data_scope` replaces the dimension values the member is limited to, each standing for itself and
     * its descendants (IA-FR-03); an empty list removes the limit. Only a user without a limit may set it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['string', 'distinct', Rule::exists('ia_roles', 'id')->where('company_id', $this->route('companyId'))],
            'data_scope' => ['sometimes', 'array'],
            'data_scope.*' => ['string', 'distinct'],
        ];
    }
}
