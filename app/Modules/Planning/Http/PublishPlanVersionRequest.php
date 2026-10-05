<?php

namespace App\Modules\Planning\Http;

use Illuminate\Foundation\Http\FormRequest;

class PublishPlanVersionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
