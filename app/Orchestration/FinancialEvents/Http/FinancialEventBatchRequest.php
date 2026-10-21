<?php

namespace App\Orchestration\FinancialEvents\Http;

use App\Modules\IdentityAccess\Contracts\AccessContexts;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The envelope of a batch of financial events (TE-FR-17, API-TR-26). Only the envelope is validated here;
 * each event is validated, allowed, and recorded on its own, so that one bad event never costs the others.
 */
class FinancialEventBatchRequest extends FormRequest
{
    public function authorize(AccessContexts $contexts): bool
    {
        return $contexts->integrationPrincipal($this) !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'list', 'min:1', 'max:'.config('api.batch.max_events')],
            'events.*' => ['array'],
        ];
    }
}
