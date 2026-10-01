<?php

namespace App\Platform\Api\Http;

use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyStatusLookup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses writes to a company that is read-only, i.e. inactive (MD-FR-01). Reads pass. Runs after
 * ResolveCompany, so callers without access to the company never learn its status.
 */
final class RejectWritesWhenReadOnly
{
    public function __construct(
        private readonly CompanyStatusLookup $status,
        private readonly CompanyContext $company,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $this->status->isWritable($this->company->require())) {
            throw new ApiException(ErrorCode::CompanyReadOnly);
        }

        return $next($request);
    }
}
