<?php

namespace App\Support\Access;

/**
 * Holds the data scope of the principal of the current request (IA-FR-03), next to CompanyContext.
 * The company access gate sets it once the principal is known and the API layer clears it after the
 * response. Outside a request (console, queue) it is unrestricted: system processes are not users.
 */
final class DataScopeContext
{
    private ?DataScope $scope = null;

    public function set(DataScope $scope): void
    {
        $this->scope = $scope;
    }

    public function forget(): void
    {
        $this->scope = null;
    }

    public function current(): DataScope
    {
        return $this->scope ?? DataScope::unrestricted();
    }
}
