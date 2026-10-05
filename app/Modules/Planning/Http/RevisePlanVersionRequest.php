<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class RevisePlanVersionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Name of the new draft; unique within the plan.
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
