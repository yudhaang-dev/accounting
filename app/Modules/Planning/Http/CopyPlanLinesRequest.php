<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class CopyPlanLinesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Copy the lines of another version of the same fiscal year ...
            'from_version_id' => ['required_without:actuals_period_id', 'prohibits:actuals_period_id', 'string', 'size:26'],
            // ... or what was posted in this period (income and expense accounts per dimension set) ...
            'actuals_period_id' => ['required_without:from_version_id', 'prohibits:from_version_id', 'string', 'size:26'],
            // ... into this period of the plan; required with `actuals_period_id`.
            'to_period_id' => ['required_with:actuals_period_id', 'prohibits:from_version_id', 'string', 'size:26'],
        ];
    }
}
