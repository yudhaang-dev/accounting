<?php

namespace App\Modules\Reporting\Http;

use Illuminate\Foundation\Http\FormRequest;

class SaveMovementClassificationRequest extends FormRequest
{
    /**
     * Shared by create and update; the rows and rules are checked in depth against the chart of
     * accounts when they are saved (RP-FR-03).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'key' => $creating ? ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'structure' => [$creating ? 'required' : 'sometimes', 'array'],
            'structure.rows' => ['required_with:structure', 'array'],
            'structure.rules' => ['required_with:structure', 'array'],
            'structure.components' => ['sometimes', 'array'],
            'lock_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
