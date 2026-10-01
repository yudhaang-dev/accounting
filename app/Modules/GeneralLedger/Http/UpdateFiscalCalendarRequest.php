<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\CalendarPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFiscalCalendarRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'pattern' => ['sometimes', Rule::enum(CalendarPattern::class)],
            'start_month' => ['sometimes', 'integer', 'between:1,12'],
        ];
    }
}
