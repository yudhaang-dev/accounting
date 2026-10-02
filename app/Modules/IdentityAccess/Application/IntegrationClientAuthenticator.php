<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\IntegrationClient;
use App\Modules\IdentityAccess\Domain\IntegrationClientStatus;
use App\Support\Company\CompanyScope;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * The `integration` guard (API-TR-22): turns a Bearer access token into the integration client it
 * was issued to. Passport checks signature, expiry and revocation of the token; the client must
 * also still be active here, so revoking or rotating a client stops its tokens on the next request
 * (IA-FR-06, IA-AC-07). Tokens of users, or without a client credentials subject, never pass.
 */
final class IntegrationClientAuthenticator
{
    /** Request attribute with the scopes the token carries and the client still holds. */
    public const SCOPES_ATTRIBUTE = 'integration_scopes';

    /** Request attribute with the authenticated client, for the company access gate. */
    public const CLIENT_ATTRIBUTE = 'integration_client';

    public function __construct(private readonly ResourceServer $server) {}

    public function __invoke(Request $request): ?IntegrationClient
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        try {
            $psr = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return null;
        }

        $oauthClientId = (string) $psr->getAttribute('oauth_client_id');
        $subject = $psr->getAttribute('oauth_user_id');

        if ($oauthClientId === '' || (! empty($subject) && $subject !== $oauthClientId)) {
            return null;
        }

        $client = IntegrationClient::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('oauth_client_id', $oauthClientId)
            ->where('status', IntegrationClientStatus::Active->value)
            ->first();

        if ($client === null) {
            return null;
        }

        /** @var list<string> $tokenScopes */
        $tokenScopes = array_values(array_map('strval', (array) $psr->getAttribute('oauth_scopes', [])));

        // A scope removed from the client stops working at once, even on tokens issued before.
        $request->attributes->set(self::CLIENT_ATTRIBUTE, $client);
        $request->attributes->set(self::SCOPES_ATTRIBUTE, array_values(array_intersect($tokenScopes, $client->scopes)));
        // Rate limits and idempotency keys are per client, not per token (API-TR-13, API-TR-15).
        $request->attributes->set('principal_key', "client:{$client->id}");

        return $client;
    }
}
