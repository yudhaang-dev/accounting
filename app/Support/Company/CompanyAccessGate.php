<?php

namespace App\Support\Company;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Decides whether the authenticated principal may act on a company (P-05, API-TR-03).
 * IdentityAccess provides the real implementation; the default binding denies everything.
 */
interface CompanyAccessGate
{
    /**
     * @throws AuthorizationException when the principal has no access to the company
     */
    public function authorize(Request $request, string $companyId): void;
}
