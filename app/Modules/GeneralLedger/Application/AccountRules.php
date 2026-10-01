<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\JournalLine;
use Illuminate\Validation\ValidationException;

/**
 * Invariants of the chart of accounts (GL-FR-01) shared by the account actions. All lookups go
 * through the company scope, so an account of another company simply does not exist here (P-05).
 */
final class AccountRules
{
    /**
     * @throws ValidationException
     */
    public function assertCodeAvailable(string $code, ?string $exceptAccountId = null): void
    {
        $taken = Account::query()
            ->where('code', $code)
            ->when($exceptAccountId !== null, fn ($query) => $query->whereKeyNot($exceptAccountId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('accounts.code_taken', ['code' => $code])]]);
        }
    }

    /**
     * The parent must exist in the company, be active, share the class of the child and have no
     * postings: an account that was posted to stays a leaf (GL-FR-01).
     *
     * @throws ValidationException
     */
    public function assertValidParent(string $parentId, AccountClass $childClass): Account
    {
        $parent = Account::query()->find($parentId)
            ?? throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_not_found')]]);

        if (! $parent->isActive()) {
            throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_inactive')]]);
        }

        if (JournalLine::query()->where('account_id', $parent->id)->exists()) {
            throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_has_postings')]]);
        }

        if ($parent->class !== $childClass) {
            throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_class_mismatch')]]);
        }

        return $parent;
    }

    /**
     * Moving an account under itself or one of its descendants would form a cycle.
     *
     * @throws ValidationException
     */
    public function assertNoCycle(Account $account, Account $newParent): void
    {
        for ($cursor = $newParent, $guard = 0; $cursor !== null && $guard < 1000; $cursor = $cursor->parent_id === null ? null : Account::query()->find($cursor->parent_id), $guard++) {
            if ($cursor->id === $account->id) {
                throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_cycle')]]);
            }
        }
    }
}
