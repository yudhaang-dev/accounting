<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class BankSuggestionsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // How many days the date of a transaction may differ from the date of the bank line.
            'window_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
        ];
    }
}
