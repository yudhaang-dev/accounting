<?php

namespace App\Modules\Reporting\Http;

use Illuminate\Foundation\Http\FormRequest;

class PlanEvaluationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'size:26'],
            'revision_version_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
