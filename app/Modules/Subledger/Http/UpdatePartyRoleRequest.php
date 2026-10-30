<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\PartyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartyRoleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'label' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(PartyStatus::class)],
        ];
    }
}
