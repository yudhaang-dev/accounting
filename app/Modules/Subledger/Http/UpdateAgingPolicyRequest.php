<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAgingPolicyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'basis' => ['required', Rule::in(['due_date', 'document_date'])],
            'buckets' => ['required', 'array', 'min:1', 'max:12'],
            'buckets.*' => ['integer', 'min:1', 'max:36500'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $buckets = $this->input('buckets');

            if (is_array($buckets) && array_values($buckets) !== collect($buckets)->unique()->sort()->values()->all()) {
                $validator->errors()->add('buckets', __('documents.aging_buckets_ascending'));
            }
        }];
    }
}
