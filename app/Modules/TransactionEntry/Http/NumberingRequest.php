<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Platform\Numbering\ResetPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NumberingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'pattern' => ['required', 'string', 'max:64'],
            'padding' => ['required', 'integer', 'between:1,18'],
            'reset' => ['required', Rule::enum(ResetPeriod::class)],
            'policy' => ['required', Rule::in([TransactionType::NUMBER_ON_CREATE, TransactionType::NUMBER_ON_SUBMIT])],
        ];
    }
}
