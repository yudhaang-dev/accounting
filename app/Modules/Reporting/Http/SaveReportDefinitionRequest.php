<?php

namespace App\Modules\Reporting\Http;

use Illuminate\Foundation\Http\FormRequest;

class SaveReportDefinitionRequest extends FormRequest
{
    /**
     * Shared by create and update; the structure is checked in depth against the chart of accounts
     * when it is saved (RP-FR-01, RP-FR-02).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'key' => $creating ? ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'kind' => $creating ? ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/'] : ['prohibited'],
            'structure' => [$creating ? 'required' : 'sometimes', 'array'],
            'structure.basis' => ['sometimes', 'string'],
            'structure.rows' => ['required_with:structure', 'array'],
            'structure.columns' => ['required_with:structure', 'array'],
            'lock_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
