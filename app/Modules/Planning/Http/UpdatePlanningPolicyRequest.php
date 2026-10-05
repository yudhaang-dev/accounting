<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanningPolicyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            // Whoever changed a version last cannot publish it.
            'require_distinct_publisher' => ['required', 'boolean'],
            // Fiscal years to set or clear; years not listed keep their default.
            'default_versions' => ['sometimes', 'array', 'max:50'],
            'default_versions.*.fiscal_year_id' => ['required', 'string', 'size:26'],
            // A published version of a plan of that fiscal year, or null to clear the default.
            'default_versions.*.plan_version_id' => ['present', 'nullable', 'string', 'size:26'],
        ];
    }
}
