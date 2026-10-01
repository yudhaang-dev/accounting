<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Domain\AccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAccountsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.class' => ['sometimes', Rule::enum(AccountClass::class)],
            'filter.status' => ['sometimes', Rule::enum(AccountStatus::class)],
            'filter.parent_id' => ['sometimes', 'string', 'size:26'],
            'filter.search' => ['sometimes', 'string', 'max:64'],
            'sort' => ['sometimes', Rule::in(['code', '-code', 'name', '-name'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
