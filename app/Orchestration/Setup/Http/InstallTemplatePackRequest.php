<?php

namespace App\Orchestration\Setup\Http;

use Illuminate\Foundation\Http\FormRequest;

class InstallTemplatePackRequest extends FormRequest
{
    /**
     * A pack is only a suggestion, so the caller must state that they reviewed it (X-AC-06).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'profile' => ['sometimes', 'string', 'max:32'],
            'components' => ['required_without:profile', 'array', 'list', 'min:1'],
            'components.*' => ['string', 'max:64', 'distinct'],
            'exclude' => ['sometimes', 'array'],
            'exclude.*' => ['array', 'list'],
            'exclude.*.*' => ['string', 'max:64', 'distinct'],
        ];
    }
}
