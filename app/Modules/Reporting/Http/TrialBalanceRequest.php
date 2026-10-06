<?php

namespace App\Modules\Reporting\Http;

use App\Platform\Api\ReportParameters;
use Illuminate\Foundation\Http\FormRequest;

class TrialBalanceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = ReportParameters::rules();

        return [
            'from' => ['required', ...$rules['from']],
            'to' => ['required', ...$rules['to']],
            ...ReportFilterRules::rules(),
            'adjustment_keys' => ['nullable', 'array', 'max:50'],
            'adjustment_keys.*' => ['string', 'regex:/^[a-z][a-z0-9_.]*$/', 'max:64'],
        ];
    }
}
