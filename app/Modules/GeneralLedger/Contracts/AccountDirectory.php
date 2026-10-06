<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Account metadata for other modules and the application layer (GL-FR-01).
 */
interface AccountDirectory
{
    /** The account, or null when it does not exist in that company. */
    public function find(string $companyId, string $accountId): ?AccountData;

    /** The account with that code, or null when the company has none. */
    public function findByCode(string $companyId, string $code): ?AccountData;

    /**
     * Every account of the company, active or not, ordered by code. Reports resolve their account
     * sets against it (RP-FR-02).
     *
     * @return list<AccountData>
     */
    public function all(string $companyId): array;

    /**
     * Classes that have no active leaf account yet; empty when the chart can take postings of every kind.
     *
     * @return list<AccountClass>
     */
    public function missingClasses(string $companyId): array;

    /**
     * Why the account cannot be the target of a role: it must exist in the company, be active and a
     * leaf, have one of the classes and, when given, accept the source (GL-FR-10). Roles of other
     * modules use the same check. Empty when the account is valid.
     *
     * @param  list<AccountClass>  $classes
     * @return list<string> messages in the language of the request
     */
    public function roleTargetProblems(string $companyId, string $accountId, array $classes, ?JournalSource $requiredSource = null): array;
}
