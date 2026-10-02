<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateDimensionValueRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'dimension_type_id' => ['required', 'string', 'size:26'],
            'code' => ['required', 'string', 'max:32', 'regex:/^\S+$/'],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'string', 'size:26'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }
}
