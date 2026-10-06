<?php

namespace App\Modules\Reporting\Http;

use Illuminate\Foundation\Http\FormRequest;

class ShowReportDefinitionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['version' => ['nullable', 'integer', 'min:1']];
    }
}
