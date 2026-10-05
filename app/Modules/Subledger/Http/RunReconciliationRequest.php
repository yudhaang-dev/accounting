<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class RunReconciliationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['as_of' => ['required', 'date_format:Y-m-d']];
    }
}
