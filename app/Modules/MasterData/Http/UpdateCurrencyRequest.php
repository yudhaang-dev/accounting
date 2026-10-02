<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Contracts\CurrencyRounding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurrencyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'scale' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'rounding_mode' => ['sometimes', Rule::enum(CurrencyRounding::class)],
        ];
    }
}
