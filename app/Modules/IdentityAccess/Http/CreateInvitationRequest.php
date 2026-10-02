<?php

namespace App\Modules\IdentityAccess\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInvitationRequest extends FormRequest
{
    /**
     * `expires_at` is when the membership lapses once accepted (e.g. an external auditor).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['string', 'distinct', Rule::exists('ia_roles', 'id')->where('company_id', $this->route('companyId'))],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
