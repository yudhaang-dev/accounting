<?php

namespace App\Modules\MasterData\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRateTypeRequest extends FormRequest
{
    /**
     * The key is not editable: it is the stable identifier of the rate type.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'label' => ['sometimes', 'string', 'max:255'],
            'max_age_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
