<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDimensionTypeRequest extends FormRequest
{
    /**
     * The key is not editable: it is the stable identifier of the type.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'label' => ['sometimes', 'string', 'max:255'],
            'is_hierarchical' => ['sometimes', 'boolean'],
            'leaf_only' => ['sometimes', 'boolean'],
        ];
    }
}
