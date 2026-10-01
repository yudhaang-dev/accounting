<?php

namespace App\Modules\IdentityAccess\Contracts;

use Illuminate\Http\Request;
use LogicException;

interface AccessContexts
{
    /** Effective permissions of the user in the company, read fresh from the roles (IA-AC-03). */
    public function forUser(int $userId, string $companyId): AccessContext;

    /**
     * The context established for this request by the company access gate.
     *
     * @throws LogicException outside a company-scoped route
     */
    public function current(Request $request): AccessContext;
}
