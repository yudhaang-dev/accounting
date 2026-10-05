<?php

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Domain\PlanVersionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlanVersionsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.plan_id' => ['sometimes', 'string', 'size:26'],
            'filter.status' => ['sometimes', Rule::enum(PlanVersionStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
