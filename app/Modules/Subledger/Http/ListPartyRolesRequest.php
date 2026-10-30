<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\PartyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPartyRolesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::enum(PartyStatus::class)],
        ];
    }
}
