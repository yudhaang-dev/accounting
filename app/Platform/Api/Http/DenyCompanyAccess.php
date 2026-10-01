<?php

namespace App\Platform\Api\Http;

use App\Support\Company\CompanyAccessGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/** Fail-closed default until IdentityAccess binds the real gate (M1-02). */
final class DenyCompanyAccess implements CompanyAccessGate
{
    public function authorize(Request $request, string $companyId): void
    {
        throw new AuthorizationException;
    }
}
