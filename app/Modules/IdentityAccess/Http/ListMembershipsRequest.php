<?php

namespace App\Modules\IdentityAccess\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMembershipsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter.status' => ['sometimes', Rule::in(['active', 'revoked', 'expired'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
