<?php

namespace App\Platform\Api\Http;

use App\Support\Company\CompanyAccessGate;
use App\Support\Company\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the `{companyId}` route segment against the principal's access and sets the company
 * context for the request (API-TR-03, P-05). The context is cleared after the response.
 */
final class ResolveCompany
{
    public function __construct(
        private readonly CompanyAccessGate $gate,
        private readonly CompanyContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = (string) $request->route('companyId');

        $this->gate->authorize($request, $companyId);
        $this->context->set($companyId);

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
