<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\CashAccountKind;
use App\Modules\Subledger\Domain\PartyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCashAccountsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::enum(PartyStatus::class)],
            'filter.kind' => ['sometimes', Rule::enum(CashAccountKind::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
