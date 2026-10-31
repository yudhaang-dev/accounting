<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\PartyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPartiesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::enum(PartyStatus::class)],
            'filter.role' => ['sometimes', 'string', 'max:32'],
            // Parties that hold any of these roles, such as the `allowed_roles` of a party field (TE-FR-04).
            'filter.roles' => ['sometimes', 'array', 'max:50'],
            'filter.roles.*' => ['string', 'max:32'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            // A reference only identifies a party together with its source system.
            'filter.source_system' => ['required_with:filter.external_ref', 'string', 'max:64'],
            'filter.external_ref' => ['required_with:filter.source_system', 'string', 'max:128'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
