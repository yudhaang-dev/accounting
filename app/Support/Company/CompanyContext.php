<?php

namespace App\Support\Company;

use LogicException;

/**
 * Holds the company that the current request or job operates on (P-05).
 * Bound as a scoped singleton; HTTP and queue layers set it before touching company data.
 */
final class CompanyContext
{
    private ?string $companyId = null;

    public function set(string $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function forget(): void
    {
        $this->companyId = null;
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    /** Fails closed: company-scoped data is never reachable without a company. */
    public function require(): string
    {
        return $this->companyId ?? throw new LogicException('No company context is set.');
    }

    public function run(string $companyId, callable $callback): mixed
    {
        $previous = $this->companyId;
        $this->companyId = $companyId;

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
