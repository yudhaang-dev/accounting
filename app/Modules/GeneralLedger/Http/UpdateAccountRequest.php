<?php

namespace App\Modules\GeneralLedger\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * The class is not editable: a different class is a different account.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'code' => ['sometimes', 'string', 'max:32', 'regex:/^\S+$/'],
            'name' => ['sometimes', 'string', 'max:255'],
            'normal_balance' => ['sometimes', Rule::in(['debit', 'credit'])],
            'parent_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }
}
