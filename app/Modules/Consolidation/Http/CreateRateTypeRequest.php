<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateRateTypeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
