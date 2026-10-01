<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateTransactionTypeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Keys starting with `system.` are defined by code and contain a dot, which this pattern excludes.
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:255'],
        ];
    }
}
