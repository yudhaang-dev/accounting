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
            'is_customer' => ['sometimes', 'boolean'],
            'is_supplier' => ['sometimes', 'boolean'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'external_refs' => ['sometimes', 'array', 'max:50'],
            'external_refs.*.source_system' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_.-]{1,63}$/'],
            'external_refs.*.external_ref' => ['required', 'string', 'max:128'],
        ];
    }
}
