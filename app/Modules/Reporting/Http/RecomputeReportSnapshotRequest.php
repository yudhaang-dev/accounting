<?php

namespace App\Modules\Reporting\Http;

use Illuminate\Foundation\Http\FormRequest;

class RecomputeReportSnapshotRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
