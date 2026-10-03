<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class MapControlAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'account_id' => ['required', 'string', 'size:26'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
