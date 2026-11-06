<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\Subledger\Domain\CashAccount;
use App\Modules\Subledger\Domain\CashAccountKind;
use App\Modules\Subledger\Domain\PartyStatus;
use App\Modules\Subledger\Domain\PostingProfileAccount;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and maintains cash and bank accounts (SL-FR-03). The currency and the ledger account are
 * chosen once; a cash account is deactivated, never deleted.
 */
final class ManageCashAccounts
{
    private const EDITABLE = ['name', 'bank_name', 'account_number'];

    public function __construct(
        private readonly Audit $audit,
        private readonly ControlAccountRules $rules,
        private readonly AccountDirectory $accounts,
        private readonly Currencies $currencies,
        private readonly CompanyDirectory $companies,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, kind, bank_name, account_number, currency, account_id
     *
     * @throws ValidationException
     */
    public function create(array $data): CashAccount
    {
        $companyId = $this->company->require();

        $this->assertCurrency($companyId, $data['currency']);
        $this->assertLedgerAccount($companyId, $data['account_id'], $data['currency']);
        $this->assertKind($data['kind'], $data['bank_name'] ?? null, $data['account_number'] ?? null);

        return $this->guarded(fn () => DB::transaction(function () use ($data): CashAccount {
            $account = CashAccount::create(array_intersect_key($data, array_flip([...self::EDITABLE, 'kind', 'currency', 'account_id'])))->refresh();

            $this->audit->record('cash_account.created', "Cash account {$account->name} created.", $account, after: $this->snapshot($account), companyId: $account->company_id);

            return $account;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  any of name, bank_name, account_number
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $accountId, array $data, int $expectedVersion): CashAccount
    {
        return $this->guarded(fn () => DB::transaction(function () use ($accountId, $data, $expectedVersion): CashAccount {
            $account = CashAccount::query()->lockForUpdate()->findOrFail($accountId);
            $changes = array_intersect_key($data, array_flip(self::EDITABLE));

            // An explicit null clears the field, so it must not fall back to the stored value.
            $merged = [...$account->only(['bank_name', 'account_number']), ...$changes];
            $this->assertKind($account->kind->value, $merged['bank_name'], $merged['account_number']);

            $before = $account->only(array_keys($changes));
            $account->updateLocked($changes, $expectedVersion);
            $after = $account->only(array_keys($changes));

            if ($before !== $after) {
                $this->audit->record('cash_account.updated', "Cash account {$account->name} updated.", $account, before: $before, after: $after, companyId: $account->company_id);
            }

            return $account->refresh();
        }));
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function deactivate(string $accountId): CashAccount
    {
        return $this->changeStatus($accountId, PartyStatus::Active, PartyStatus::Inactive, 'cash_account.deactivated');
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function reactivate(string $accountId): CashAccount
    {
        return $this->changeStatus($accountId, PartyStatus::Inactive, PartyStatus::Active, 'cash_account.reactivated');
    }

    private function changeStatus(string $accountId, PartyStatus $from, PartyStatus $to, string $event): CashAccount
    {
        return DB::transaction(function () use ($accountId, $from, $to, $event): CashAccount {
            $account = CashAccount::query()->lockForUpdate()->findOrFail($accountId);

            if ($account->status !== $from) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $account->status = $to;
            $account->save();

            $this->audit->record(
                $event,
                "Cash account {$account->name} changed from {$from->value} to {$to->value}.",
                $account,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                companyId: $account->company_id,
            );

            return $account->refresh();
        });
    }

    /** @throws ValidationException */
    private function assertCurrency(string $companyId, string $currency): void
    {
        if (! $this->currencies->isActive($companyId, $currency)) {
            throw ValidationException::withMessages(['currency' => [__('cash.currency_not_active', ['currency' => $currency])]]);
        }
    }

    /**
     * The ledger account must be an active asset leaf that takes the subledger source only, accept the
     * currency of the cash account, and not be the account of another cash account.
     *
     * @throws ValidationException
     */
    private function assertLedgerAccount(string $companyId, string $accountId, string $currency): void
    {
        $problems = $this->rules->problemsForClass($companyId, $accountId, AccountClass::Asset);
        $account = $this->accounts->find($companyId, $accountId);

        if ($account !== null) {
            $company = $this->companies->find($companyId);
            $functional = $company === null ? $currency : $company->functionalCurrency;

            if (! $account->acceptsCurrency($currency, $functional)) {
                $problems[] = __('ledger.currency_not_allowed', ['account' => $account->code, 'currency' => $currency]);
            }

            if (CashAccount::query()->where('account_id', $accountId)->exists()) {
                $problems[] = __('cash.ledger_account_taken', ['account' => $account->code]);
            }

            // A receivable or payable control account carries party balances; cash on it would mix the two reconciliations.
            if (PostingProfileAccount::query()->where('account_id', $accountId)->exists()) {
                $problems[] = __('cash.ledger_account_is_control', ['account' => $account->code]);
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['account_id' => $problems]);
        }
    }

    /** A cash box has no bank details; a bank account names its bank and number. */
    private function assertKind(string $kind, ?string $bankName, ?string $accountNumber): void
    {
        if ($kind === CashAccountKind::Cash->value && ($bankName !== null || $accountNumber !== null)) {
            throw ValidationException::withMessages(['kind' => [__('cash.cash_has_no_bank_details')]]);
        }

        if ($kind === CashAccountKind::Bank->value && ($bankName === null || $accountNumber === null)) {
            throw ValidationException::withMessages(['kind' => [__('cash.bank_needs_details')]]);
        }
    }

    /** The name is unique per company; a concurrent request may take it between check and insert. */
    private function guarded(callable $work): CashAccount
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [__('cash.name_or_account_taken')]]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(CashAccount $account): array
    {
        return $account->only(['name', 'kind', 'bank_name', 'account_number', 'currency', 'account_id', 'status']);
    }
}
