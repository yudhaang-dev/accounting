<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class AllocateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'open_item_id' => ['required', 'string', 'size:26'],
            'counterpart_open_item_id' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,6})?$/'],
            'allocation_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
