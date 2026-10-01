<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Foundation\Http\FormRequest;

/** Reason of a rejection (required) or a cancellation (optional), TE-FR-07. */
class ReasonRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => [$this->routeIs('entries.reject') ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
