<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class EnterPlanLinesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Cells to enter, at most 500 per request. A cell is a period, an account and a dimension set;
            // a cell that exists takes the new amount and note.
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.period_id' => ['required', 'string', 'size:26'],
            'lines.*.account_id' => ['required', 'string', 'size:26'],
            // Dimension type key => dimension value ID; leave out for general activity.
            'lines.*.dimensions' => ['sometimes', 'nullable', 'array'],
            'lines.*.dimensions.*' => ['nullable', 'string', 'size:26'],
            // Decimal string in the functional currency, at most 6 decimals.
            'lines.*.amount' => ['required', 'string', 'regex:/^-?[0-9]{1,18}(\.[0-9]{1,6})?$/'],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
