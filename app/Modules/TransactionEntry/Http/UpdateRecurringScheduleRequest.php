<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Validation\Rule;

class UpdateRecurringScheduleRequest extends RecurringScheduleRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            ...$this->contentRules(),
        ];
    }
}
