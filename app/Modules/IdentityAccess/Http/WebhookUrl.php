<?php

namespace App\Modules\IdentityAccess\Http;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Where a webhook may be sent (API-TR-25): an https URL of a public host, without credentials in it. The
 * server makes the call, so a URL to localhost or a private address would turn a client into a way in to
 * the network behind it. Plain http is accepted only while developing and testing. The host is judged by
 * its name here; the address it resolves to at the time of the call is not checked.
 */
final class WebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        $schemeAllowed = $scheme === 'https' || ($scheme === 'http' && app()->environment(['local', 'testing']));

        if ($host === '' || ! $schemeAllowed || isset($parts['user']) || isset($parts['pass']) || $this->isInternal($host)) {
            $fail(__('integration.webhook_url_invalid'));
        }
    }

    private function isInternal(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // A host without a dot is a name on the local network.
        return ! str_contains($host, '.');
    }
}
