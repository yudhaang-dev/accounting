<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\GroupAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGroupAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            // The element of the statement the account belongs to; fixed once created.
            'class' => ['required', 'string', Rule::in(GroupAccount::CLASSES)],
            // How the account enters the group cash flow; only for assets, liabilities and equity, and `cash` only for assets.
            'cash_flow_class' => ['nullable', 'string', Rule::in(GroupAccount::CASH_FLOW_CLASSES)],
        ];
    }
}
