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
            // A party may be both; at least one role is required.
            'is_customer' => ['required_without:is_supplier', 'boolean'],
            'is_supplier' => ['required_without:is_customer', 'boolean'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            // The profile deciding its control accounts; empty means the default profile (SL-FR-02).
            'posting_profile_id' => ['nullable', 'string', 'size:26'],
            // How source systems refer to this party; one reference belongs to one party per source system.
            'external_refs' => ['sometimes', 'array', 'max:50'],
            'external_refs.*.source_system' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_.-]{1,63}$/'],
            'external_refs.*.external_ref' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator) {
            if (! $this->boolean('is_customer') && ! $this->boolean('is_supplier') && ! $validator->errors()->hasAny(['is_customer', 'is_supplier'])) {
                $validator->errors()->add('is_customer', __('parties.role_required'));
            }
        }];
    }
}
