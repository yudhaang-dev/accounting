<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Domain\DimensionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDimensionValuesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.dimension_type_id' => ['sometimes', 'string', 'size:26'],
            'filter.status' => ['sometimes', Rule::enum(DimensionStatus::class)],
            'filter.parent_id' => ['sometimes', 'string', 'size:26'],
            'filter.search' => ['sometimes', 'string', 'max:64'],
            'sort' => ['sometimes', Rule::in(['code', '-code', 'name', '-name'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
