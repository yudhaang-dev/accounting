<?php

namespace App\Modules\GeneralLedger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ImportTemplateRequest extends FormRequest
{
    /**
     * A template is only a suggestion, so the caller must state that they reviewed it (X-AC-03).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'max:64'],
            'confirmed' => ['required', 'accepted'],
            'exclude' => ['sometimes', 'array'],
            'exclude.*' => ['string', 'max:32', 'distinct'],
        ];
    }
}
