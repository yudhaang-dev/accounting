<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreatePartyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Keys of the party roles of the company it holds (SL-FR-01); a role says nothing about the side of the ledger.
            'roles' => ['sometimes', 'array', 'max:20'],
            'roles.*' => ['string', 'distinct', 'max:32'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            // The profile deciding its control accounts; empty means the default profile (SL-FR-02).
            'posting_profile_id' => ['nullable', 'string', 'size:26'],
            // The group company this party is, for intragroup elimination (GL-FR-15); stored as given.
            'counterparty_company_id' => ['nullable', 'string', 'ulid'],
            // How source systems refer to this party; one reference belongs to one party per source system.
            'external_refs' => ['sometimes', 'array', 'max:50'],
            'external_refs.*.source_system' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_.-]{1,63}$/'],
            'external_refs.*.external_ref' => ['required', 'string', 'max:128'],
        ];
    }
}
