<?php

namespace App\Modules\Reporting\Http;

use App\Platform\Api\ReportParameters;
use Illuminate\Foundation\Http\FormRequest;

class EvaluateClassificationRequest extends FormRequest
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
            'version' => ['nullable', 'integer', 'min:1'],
            ...ReportFilterRules::rules(),
        ];
    }
}
