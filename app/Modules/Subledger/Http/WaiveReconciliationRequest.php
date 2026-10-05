<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class WaiveReconciliationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
