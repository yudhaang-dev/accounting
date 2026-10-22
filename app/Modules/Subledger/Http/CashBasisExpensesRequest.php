<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class CashBasisExpensesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
