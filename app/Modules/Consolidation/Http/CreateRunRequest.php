<?php

namespace App\Modules\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class CreateRunRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The range the packages stand for; `to` is the cutoff date.
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Asks for a formal consolidated statement; refused, naming what stands in the way, when the group cannot give one.
            'formal' => ['sometimes', 'boolean'],
        ];
    }
}
