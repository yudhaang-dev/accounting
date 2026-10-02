<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSecurityPolicyRequest extends FormRequest
{
    /**
     * The whole policy is replaced as a new version. `lock_version` is the `version` you read (0 when
     * the company has no policy yet). `idle_timeout_minutes: null` means sessions never time out.
     * `client_ip_allowlist` lists IPs or CIDR ranges; empty means integration clients may call from anywhere.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            // Keys of GET /permissions.
            'two_factor_permissions' => ['present', 'array'],
            'two_factor_permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::all())],
            'idle_timeout_minutes' => ['present', 'nullable', 'integer', 'min:1', 'max:1440'],
            'client_ip_allowlist' => ['present', 'array', 'max:100'],
            'client_ip_allowlist.*' => ['string', 'distinct', $this->ipOrCidr(...)],
        ];
    }

    private function ipOrCidr(string $attribute, mixed $value, Closure $fail): void
    {
        [$address, $mask] = array_pad(explode('/', (string) $value, 2), 2, null);
        $ipv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $ipv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $maskOk = $mask === null || (ctype_digit($mask) && (int) $mask <= ($ipv4 ? 32 : 128));

        if ((! $ipv4 && ! $ipv6) || ! $maskOk) {
            $fail(__('security.ip_invalid'));
        }
    }
}
