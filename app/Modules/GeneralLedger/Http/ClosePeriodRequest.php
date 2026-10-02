<?php

namespace App\Modules\GeneralLedger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ClosePeriodRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
