<?php

namespace App\Orchestration\Approval\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApprovalPolicyRequest extends FormRequest
{
    /**
     * The whole policy is replaced as a new version. `lock_version` is the `version` you read (0 when the
     * company has no policy yet). `separation_of_duties` says that the creator of an entry cannot approve
     * it. An entry takes the first of the `rules` whose `transaction_type_key`, `source` and `threshold`
     * it meets (a null condition matches everything; `threshold` is compared with the total debit of the
     * entry in the functional currency, and the entry must be above it). `stages` lists the permission
     * each approval stage needs, in order; empty approves the entry automatically. `auto_post` posts the
     * entry as soon as the last stage approved it. An entry no rule matches needs one approval by a holder
     * of `entries.approve`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'separation_of_duties' => ['required', 'boolean'],
            'rules' => ['present', 'array', 'max:100'],
            'rules.*.transaction_type_key' => ['present', 'nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'rules.*.source' => ['present', 'nullable', Rule::in(['manual', 'integration', 'recurring'])],
            'rules.*.threshold' => ['present', 'nullable', 'decimal:0,6', 'min:0'],
            'rules.*.stages' => ['present', 'array', 'max:5'],
            'rules.*.stages.*' => ['string', 'max:64'],
            'rules.*.auto_post' => ['required', 'boolean'],
        ];
    }
}
