<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\RecurringSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A recurring schedule (TE-FR-14). The template carries what an entry carries except its date, which
 * is the date of the occurrence: fixed values only, no formulas.
 */
class RecurringScheduleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function contentRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'transaction_type_version' => ['nullable', 'integer', 'min:1'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'initial_status' => ['sometimes', Rule::in(['draft', 'submitted'])],
            'template' => ['required', 'array'],
            'template.description' => ['required', 'string', 'max:500'],
            'template.document_date' => ['nullable', 'date_format:Y-m-d'],
            'template.currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'template.exchange_rate' => ['nullable', 'string', 'max:40'],
            'template.lines' => ['sometimes', 'array', 'max:500'],
            'template.lines.*' => ['array'],
            'template.lines.*.account_id' => ['required', 'string', 'size:26'],
            'template.lines.*.debit' => ['nullable', 'string', 'max:40'],
            'template.lines.*.credit' => ['nullable', 'string', 'max:40'],
            'template.lines.*.description' => ['nullable', 'string', 'max:500'],
            'template.lines.*.dimensions' => ['nullable', 'array'],
            'template.lines.*.dimensions.*' => ['nullable', 'string', 'size:26'],
            'template.lines.*.currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'template.lines.*.exchange_rate' => ['nullable', 'string', 'max:40'],
            'template.values' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transaction_type_key' => ['required', 'string', 'max:64'],
            'frequency' => ['required', Rule::in(RecurringSchedule::FREQUENCIES)],
            'interval_count' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            ...$this->contentRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $start = $this->input('start_date');
                $end = $this->input('end_date');

                if (is_string($start) && is_string($end) && $end < $start) {
                    $validator->errors()->add('end_date', __('entries.schedule_end_before_start'));
                }
            },
        ];
    }
}
