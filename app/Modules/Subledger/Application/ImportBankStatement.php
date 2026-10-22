<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\BankImportMapping;
use App\Modules\Subledger\Domain\BankStatement;
use App\Modules\Subledger\Domain\BankStatementLine;
use App\Modules\Subledger\Domain\CashAccount;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports a bank statement into a cash account with the mapping of that account (SL-FR-13). The file is
 * read all or nothing, and the same file is not imported twice into one account.
 */
final class ImportBankStatement
{
    public function __construct(
        private readonly BankStatementParser $parser,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  string  $closingBalance  the balance the bank reports on the closing date
     * @param  string|null  $closingDate  `YYYY-MM-DD`; the last date of the file when empty
     *
     * @throws ValidationException
     */
    public function __invoke(string $cashAccountId, string $csv, ?string $fileName, string $closingBalance, ?string $closingDate, int $actorId): BankStatement
    {
        $account = CashAccount::query()->findOrFail($cashAccountId);
        $mapping = BankImportMapping::query()->where('cash_account_id', $cashAccountId)->first();

        if ($mapping === null) {
            throw ValidationException::withMessages(['cash_account_id' => [__('bank.mapping_missing')]]);
        }

        $rows = $this->parser->parse($csv, $mapping);
        $from = $to = $rows[0]['date'];

        foreach ($rows as $row) {
            $from = min($from, $row['date']);
            $to = max($to, $row['date']);
        }

        $hash = hash('sha256', $csv);

        try {
            return DB::transaction(function () use ($account, $rows, $fileName, $closingBalance, $closingDate, $actorId, $hash, $from, $to): BankStatement {
                if (BankStatement::query()->where('cash_account_id', $account->id)->where('file_hash', $hash)->exists()) {
                    throw ValidationException::withMessages(['file' => [__('bank.statement_already_imported')]]);
                }

                $statement = BankStatement::create([
                    'cash_account_id' => $account->id,
                    'file_name' => $fileName,
                    'file_hash' => $hash,
                    'period_from' => $from,
                    'period_to' => $to,
                    'closing_date' => $closingDate ?? $to,
                    'closing_balance' => $closingBalance,
                    'line_count' => count($rows),
                    'imported_by' => $actorId,
                ]);

                foreach ($rows as $row) {
                    BankStatementLine::create([
                        'statement_id' => $statement->id,
                        'cash_account_id' => $account->id,
                        'line_number' => $row['line'],
                        'transaction_date' => $row['date'],
                        'description' => $row['description'],
                        'reference' => $row['reference'],
                        'direction' => $row['direction'],
                        'amount' => $row['amount'],
                    ]);
                }

                $this->audit->record(
                    'bank_statement.imported',
                    "Statement of {$account->name} imported with ".count($rows).' lines.',
                    $statement,
                    properties: ['cash_account_id' => $account->id, 'lines' => count($rows), 'period_from' => $from, 'period_to' => $to, 'closing_balance' => $closingBalance],
                    companyId: $account->company_id,
                );

                return $statement->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request imported the same file between the check and the insert.
            throw ValidationException::withMessages(['file' => [__('bank.statement_already_imported')]]);
        }
    }
}
