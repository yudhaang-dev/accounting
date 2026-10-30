<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePartyRequest extends FormRequest
{
    /**
     * `external_refs` lists references to add; existing ones are kept.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            // The whole set of roles the party holds.
            'roles' => ['sometimes', 'array', 'max:20'],
            'roles.*' => ['string', 'distinct', 'max:32'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The profile deciding its control accounts; empty means the default profile (SL-FR-02).
            'posting_profile_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'counterparty_company_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'external_refs' => ['sometimes', 'array', 'max:50'],
            'external_refs.*.source_system' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_.-]{1,63}$/'],
            'external_refs.*.external_ref' => ['required', 'string', 'max:128'],
        ];
    }
}
