<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateDimensionTypeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Stable identifier used in API payloads (`dimensions` objects, `dimensions[<key>]` filters).
            'key' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:255'],
            'is_hierarchical' => ['sometimes', 'boolean'],
            'leaf_only' => ['sometimes', 'boolean'],
        ];
    }
}
