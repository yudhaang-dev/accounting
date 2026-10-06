<?php

namespace App\Modules\Reporting\Http;

use App\Platform\Api\ReportParameters;
use Illuminate\Foundation\Http\FormRequest;

class EvaluateReportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = ReportParameters::rules();

        return [
            'from' => ['required_without:as_of', ...$rules['from']],
            'to' => ['required_without:as_of', ...$rules['to']],
            'as_of' => $rules['as_of'],
            'version' => ['nullable', 'integer', 'min:1'],
            ...ReportFilterRules::rules(),
        ];
    }
}
