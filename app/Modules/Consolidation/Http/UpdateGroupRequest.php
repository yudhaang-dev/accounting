<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGroupRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            // What a run does with a package that is not closed or does not cover its range: keep it as a draft or refuse it.
            'package_policy' => ['sometimes', 'string', 'in:draft,reject'],
        ];
    }
}
