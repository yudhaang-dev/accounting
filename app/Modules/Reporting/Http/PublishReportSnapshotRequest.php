<?php

namespace App\Modules\Reporting\Http;

class PublishReportSnapshotRequest extends EvaluateReportRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'reason' => ['nullable', 'string', 'max:255']];
    }
}
