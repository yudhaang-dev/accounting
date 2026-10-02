<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;

class LookupExchangeRateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rate_type' => ['required', 'string', 'max:32'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
