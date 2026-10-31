<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    /**
     * The period and the scope are fixed once a version has been published.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            'objective' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'],
            'scope_dimension_value_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
