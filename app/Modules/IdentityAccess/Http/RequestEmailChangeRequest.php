<?php

namespace App\Modules\IdentityAccess\Http;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestEmailChangeRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->user()?->getAuthIdentifier())],
            'current_password' => $this->currentPasswordRules(),
        ];
    }
}
