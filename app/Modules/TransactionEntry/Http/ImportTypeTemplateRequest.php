<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Foundation\Http\FormRequest;

class ImportTypeTemplateRequest extends FormRequest
{
    /**
     * A template is only a suggestion, so the caller must state that they reviewed it and name the
     * types they want (TE-FR-01, X-AC-03).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'max:64'],
            'confirmed' => ['required', 'accepted'],
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string', 'max:64', 'distinct'],
        ];
    }
}
