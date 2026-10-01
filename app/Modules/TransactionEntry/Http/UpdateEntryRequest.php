<?php

namespace App\Modules\TransactionEntry\Http;

class UpdateEntryRequest extends EntryRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            ...$this->contentRules(),
        ];
    }
}
