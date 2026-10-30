<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Foundation\Http\FormRequest;

class DescribeTransactionTypeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['present', 'nullable', 'string', 'max:500'],
        ];
    }
}
