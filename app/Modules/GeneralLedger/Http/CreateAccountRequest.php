<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^\S+$/'],
            'name' => ['required', 'string', 'max:255'],
            'class' => ['required', Rule::enum(AccountClass::class)],
            // Defaults to the normal balance of the class; the opposite is allowed for contra accounts.
            'normal_balance' => ['nullable', Rule::in(['debit', 'credit'])],
            'parent_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
