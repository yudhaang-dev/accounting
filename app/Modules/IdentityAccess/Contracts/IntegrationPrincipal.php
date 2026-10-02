<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * The authenticated integration client of a request (API-TR-24): its company, `source_system`,
 * allowlist of transaction type keys and the scopes its token may use. The application layer passes
 * it to `TransactionEntry`, which takes `source_system` from here, never from the request body.
 */
final readonly class IntegrationPrincipal
{
    /**
     * @param  list<string>  $transactionTypeKeys  empty means no transaction type is allowed
     * @param  list<string>  $scopes  scopes the token carries and the client still holds
     */
    public function __construct(
        public string $clientId,
        public string $companyId,
        public string $sourceSystem,
        public array $transactionTypeKeys,
        public array $scopes,
    ) {}

    public function allowsTransactionType(string $key): bool
    {
        return in_array($key, $this->transactionTypeKeys, true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
