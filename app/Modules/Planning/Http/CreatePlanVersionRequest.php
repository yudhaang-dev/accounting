<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreatePlanVersionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Unique within the plan.
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
