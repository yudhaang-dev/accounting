<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Contracts\ControlRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgingReportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['required', 'date_format:Y-m-d'],
            'control_role' => ['required', Rule::enum(ControlRole::class)],
            'party_id' => ['sometimes', 'string', 'size:26'],
            'basis' => ['sometimes', Rule::in(['due_date', 'document_date'])],
        ];
    }
}
