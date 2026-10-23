<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Where the adjustment comes from: a document, a calculation, an instruction.
            'source' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.group_account_id' => ['required', 'ulid'],
            // One side per line, in the presentation currency of the group.
            'lines.*.debit' => ['required', 'decimal:0,6', 'min:0'],
            'lines.*.credit' => ['required', 'decimal:0,6', 'min:0'],
        ];
    }
}
