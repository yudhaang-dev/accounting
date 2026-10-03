<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ClearCashTransactionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The date the bank or cash count confirmed the transaction; today when empty.
            'cleared_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
