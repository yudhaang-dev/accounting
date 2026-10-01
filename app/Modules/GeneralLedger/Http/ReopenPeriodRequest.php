<?php

namespace App\Modules\GeneralLedger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ReopenPeriodRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
