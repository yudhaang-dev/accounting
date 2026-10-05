<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostedJournalLine;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\Subledger\Contracts\CashAccountData;
use App\Modules\Subledger\Contracts\CashAccountDirectory;
use App\Modules\Subledger\Contracts\CashTransfer;
use App\Modules\Subledger\Domain\CashAccount;
use App\Modules\Subledger\Domain\CashDirection;
use App\Modules\Subledger\Domain\CashTransaction;
use App\Modules\Subledger\Domain\PostingProfileAccount;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Posts transfers between cash accounts (SL-FR-03, SL-FR-11, TE-AC-10). The accounts take the source
 * `subledger` only, so the cash a transfer moves is recorded here and nowhere else.
 */
final class CashTransferPosting
{
    public function __construct(
        private readonly CashAccountDirectory $cashAccounts,
        private readonly AccountDirectory $accounts,
        private readonly CompanyDirectory $companies,
        private readonly PostingValidator $validator,
        private readonly PostingService $posting,
        private readonly CompanyContext $company,
    ) {}

    public function validate(CashTransfer $transfer): void
    {
        $this->company->run($transfer->journal->companyId, function () use ($transfer): void {
            $this->check($transfer);
            $this->validator->validate($transfer->journal);
        });
    }

    public function post(CashTransfer $transfer): PostedJournal
    {
        return $this->company->run($transfer->journal->companyId, fn (): PostedJournal => DB::transaction(function () use ($transfer): PostedJournal {
            [$from, $to] = $this->check($transfer);
            $journal = $this->posting->post($transfer->journal);

            if (! $journal->replayed) {
                $this->record($from, CashDirection::Out, $journal);
                $this->record($to, CashDirection::In, $journal);
            }

            return $journal;
        }));
    }

    /**
     * @return array{CashAccountData, CashAccountData}
     *
     * @throws ValidationException
     */
    private function check(CashTransfer $transfer): array
    {
        $command = $transfer->journal;

        if ($command->source !== JournalSource::Subledger) {
            throw new LogicException('The journal of a cash transfer is posted from the source subledger.');
        }

        $companyId = $command->companyId;
        $from = $this->cashAccounts->find($companyId, $transfer->fromCashAccountId);
        $to = $this->cashAccounts->find($companyId, $transfer->toCashAccountId);
        $errors = [];

        foreach ([['cash_account_id', $from], ['counterpart_cash_account_id', $to]] as [$key, $account]) {
            if ($account === null) {
                $errors[$key][] = __('documents.cash_account_unknown');
            } elseif (! $account->isActive) {
                $errors[$key][] = __('documents.cash_account_inactive', ['name' => $account->name]);
            }
        }

        if ($from !== null && $to !== null && $from->id === $to->id) {
            $errors['counterpart_cash_account_id'][] = __('documents.transfer_same_account');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        assert($from !== null && $to !== null);

        $lineErrors = $this->lineErrors($transfer, $from, $to);

        if ($lineErrors !== []) {
            throw ValidationException::withMessages(['lines' => $lineErrors]);
        }

        return [$from, $to];
    }

    /**
     * Cash leaves the first account and enters the second, each in the currency of its account, and the
     * journal touches no other cash account and no control account.
     *
     * @return list<string>
     */
    private function lineErrors(CashTransfer $transfer, CashAccountData $from, CashAccountData $to): array
    {
        $command = $transfer->journal;
        $functional = $this->companies->find($command->companyId)->functionalCurrency ?? '';
        $errors = [];
        $out = 0;
        $in = 0;

        foreach ($command->lines as $line) {
            $side = BigDecimal::of($line->debit)->isPositive() ? 'debit' : 'credit';
            $currency = $line->currency ?? $functional;

            foreach ([[$from, 'credit'], [$to, 'debit']] as [$account, $expected]) {
                if ($line->accountId !== $account->accountId) {
                    continue;
                }

                $expected === 'credit' ? $out++ : $in++;

                if ($side !== $expected) {
                    $errors[] = __('documents.transfer_line_side', ['name' => $account->name, 'side' => $expected]);
                }

                if ($currency !== $account->currency) {
                    $errors[] = __('documents.cash_line_currency', ['name' => $account->name, 'currency' => $account->currency]);
                }
            }
        }

        if ($out === 0) {
            $errors[] = __('documents.cash_line_missing', ['name' => $from->name]);
        }

        if ($in === 0) {
            $errors[] = __('documents.cash_line_missing', ['name' => $to->name]);
        }

        return array_values(array_unique([...$errors, ...$this->strayLines($transfer, $from, $to)]));
    }

    /**
     * @return list<string>
     */
    private function strayLines(CashTransfer $transfer, CashAccountData $from, CashAccountData $to): array
    {
        $companyId = $transfer->journal->companyId;
        $accountIds = array_values(array_unique(array_map(fn (PostJournalLine $line): string => $line->accountId, $transfer->journal->lines)));

        $controls = PostingProfileAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereIn('account_id', $accountIds)->pluck('account_id')->all();
        $cashes = CashAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereIn('account_id', $accountIds)->pluck('account_id')->all();

        $errors = [];

        foreach ($controls as $accountId) {
            $errors[] = __('documents.stray_control_line', ['account' => $this->accounts->find($companyId, $accountId)->code ?? $accountId]);
        }

        foreach (array_diff($cashes, [$from->accountId, $to->accountId]) as $accountId) {
            $errors[] = __('documents.stray_cash_line', ['account' => $this->accounts->find($companyId, $accountId)->code ?? $accountId]);
        }

        return $errors;
    }

    /** Records the cash of the lines to the account of `$cash`, in the currency of the account. */
    private function record(CashAccountData $cash, CashDirection $direction, PostedJournal $journal): void
    {
        $amount = array_reduce(
            array_filter($journal->lines, fn (PostedJournalLine $line): bool => $line->accountId === $cash->accountId),
            fn (BigDecimal $sum, PostedJournalLine $line): BigDecimal => $sum->plus($line->transactionAmount),
            BigDecimal::zero(),
        );

        CashTransaction::create([
            'cash_account_id' => $cash->id,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $cash->currency,
            'transaction_date' => $journal->accountingDate,
            'reference' => $journal->number,
            'journal_id' => $journal->id,
        ]);
    }
}
