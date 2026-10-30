<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ImportPartyRolesRequest extends FormRequest
{
    /**
     * The roles are only a suggestion, so the caller must state that they reviewed them and name the
     * ones they want (SL-FR-01, X-AC-03).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string', 'max:32', 'distinct'],
        ];
    }
}
