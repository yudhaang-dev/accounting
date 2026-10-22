<?php

namespace App\Orchestration\Consolidation\Http;

use Illuminate\Foundation\Http\FormRequest;

class ProposeMemberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'string', 'ulid'],
            // The accountant's reasoning for the assessment that the group controls the company (CO-FR-02).
            'control_basis' => ['required', 'string', 'max:2000'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
