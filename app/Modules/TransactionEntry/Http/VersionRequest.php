<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Domain\FieldType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Content of a draft version: the mode and, for `structured`, its field definitions (TE-FR-04).
 * Whether referenced accounts and dimension types exist is checked by the application layer.
 */
class VersionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(TransactionTypeMode::class)],
            'fields' => ['present', 'array', 'max:50'],
            'fields.*.name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.type' => ['required', Rule::enum(FieldType::class)],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.account_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'fields.*.account_ids.*' => ['string', 'size:26'],
            'fields.*.dimension_type' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
