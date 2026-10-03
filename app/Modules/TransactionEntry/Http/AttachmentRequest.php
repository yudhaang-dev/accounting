<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class AttachmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', File::types(config('attachments.types'))->max(config('attachments.max_kilobytes'))],
        ];
    }
}
