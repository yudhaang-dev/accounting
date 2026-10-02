<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Contracts\CurrencyRounding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivateCurrencyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            // Defaults to the ISO minor unit of the currency.
            'scale' => ['nullable', 'integer', 'min:0', 'max:6'],
            'rounding_mode' => ['nullable', Rule::enum(CurrencyRounding::class)],
        ];
    }
}
