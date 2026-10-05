<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreatePlanRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The fiscal year the plan projects; it cannot change afterwards.
            'fiscal_year_id' => ['required', 'string', 'size:26'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
