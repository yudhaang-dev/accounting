<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Domain\CalendarPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFiscalCalendarRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'pattern' => ['required', Rule::enum(CalendarPattern::class)],
            // Month (1 = January) in which a monthly fiscal year starts; the year always starts on the 1st.
            // 4-4-5 and free calendars start on the date of their first fiscal year instead.
            'start_month' => ['required_if:pattern,monthly', 'prohibited_unless:pattern,monthly', 'integer', 'between:1,12'],
        ];
    }
}
