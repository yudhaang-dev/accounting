<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\Subledger\Contracts\ControlRole;

/**
 * What makes an account a valid control account (SL-FR-02): an active leaf of the class the role
 * needs that accepts postings from the subledger and from nothing else (GL-FR-02).
 */
final class ControlAccountRules
{
    public function __construct(private readonly AccountDirectory $accounts) {}

    /** @return list<string> messages in the language of the request; empty when the account is valid */
    public function problems(string $companyId, string $accountId, ControlRole $role): array
    {
        return $this->problemsForClass($companyId, $accountId, AccountClass::from($role->accountClass()));
    }

    /**
     * The same rule for any ledger account the subledger owns the balance of, such as a cash account.
     *
     * @return list<string> messages in the language of the request; empty when the account is valid
     */
    public function problemsForClass(string $companyId, string $accountId, AccountClass $class): array
    {
        $problems = $this->accounts->roleTargetProblems($companyId, $accountId, [$class], JournalSource::Subledger);

        $account = $this->accounts->find($companyId, $accountId);

        if ($account !== null && $account->allowedSources !== [JournalSource::Subledger]) {
            $problems[] = __('posting_profiles.subledger_only', ['account' => $account->code]);
        }

        return $problems;
    }
}
