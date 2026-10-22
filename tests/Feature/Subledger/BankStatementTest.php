<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Subledger\Domain\BankStatementLine;
use App\Modules\Subledger\Domain\CashTransaction;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;

// Bank reconciliation by statement import (SL-FR-13): the column mapping of a cash account, the import of a
// statement, matching to cash transactions with suggestions, and the reconciliation report.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bankLedger = bankLedgerAccount('1112');
    $this->salesLedger = bankLedgerAccount('4110');

    $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$this->bankLedger}")->json('data.lock_version');
    $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->bankLedger}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();

    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890', 'currency' => 'IDR', 'account_id' => $this->bankLedger,
    ])->assertCreated()->json('data.id');
});

function bankLedgerAccount(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

/** A cash transaction as a document would record it. */
function bankBook(array $overrides = []): CashTransaction
{
    return app(CompanyContext::class)->run(test()->companyId, fn () => CashTransaction::create(array_merge([
        'cash_account_id' => test()->cashAccount,
        'direction' => 'in',
        'amount' => '1500000',
        'currency' => 'IDR',
        'transaction_date' => '2026-03-10',
        'reference' => 'INV-001',
    ], $overrides)));
}

/** The ledger side of cash that moved, so the book balance of the account has something in it. */
function bankLedgerMovement(string $amount, string $date = '2026-03-01'): void
{
    $debit = bcadd($amount, '0', 2);

    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Cash movement',
        source: JournalSource::Subledger,
        lines: [new PostJournalLine(test()->bankLedger, $debit, '0'), new PostJournalLine(test()->salesLedger, '0', $debit)],
        postedBy: test()->admin->id,
    ));
}

function bankMapping(array $overrides = [])
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/cash-accounts/'.test()->cashAccount.'/import-mapping', array_merge([
        'delimiter' => ',', 'date_format' => 'Y-m-d', 'decimal_separator' => '.', 'has_header' => true,
        'date_column' => 1, 'description_column' => 2, 'reference_column' => 3, 'amount_column' => 4,
    ], $overrides));
}

function importStatement(string $csv, array $overrides = [], ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/cash-accounts/'.test()->cashAccount.'/bank-statements', array_merge([
        'csv' => $csv, 'closing_balance' => '2500000',
    ], $overrides));
}

function matchLine(string $lineId, string $transactionId, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url."/bank-statement-lines/{$lineId}/match", ['cash_transaction_id' => $transactionId]);
}

function suggestionsOf(string $statementId, string $query = '')
{
    return test()->actingAs(test()->admin)->getJson(test()->url."/bank-statements/{$statementId}/suggestions{$query}");
}

const BANK_CSV = "date,description,reference,amount\n2026-03-10,Transfer PT Maju INV-001,INV-001,1500000\n2026-03-12,Biaya admin,,-25000\n2026-03-15,Bunga,,10000\n";

describe('the column mapping of a cash account', function () {
    it('has none until it is saved, then is created once and changed with the version that was read', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/cash-accounts/{$this->cashAccount}/import-mapping")->assertOk()->assertJsonPath('data', null);

        $created = bankMapping()->assertCreated()->json('data');
        expect($created)->toMatchArray(['delimiter' => ',', 'date_format' => 'Y-m-d', 'date_column' => 1, 'amount_column' => 4, 'money_in_column' => null, 'lock_version' => 1]);

        bankMapping(['delimiter' => ';'])->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        $changed = bankMapping(['delimiter' => ';', 'lock_version' => 1])->assertOk()->json('data');
        expect($changed['delimiter'])->toBe(';')->and($changed['lock_version'])->toBe(2);

        bankMapping(['lock_version' => 1])->assertStatus(409);
        expect(AuditEntry::where('event', 'bank_import_mapping.created')->count())->toBe(1)
            ->and(AuditEntry::where('event', 'bank_import_mapping.updated')->count())->toBe(1);
    });

    it('takes one amount column or a pair for money in and money out, never both or half', function () {
        bankMapping(['amount_column' => null])->assertUnprocessable()->assertJsonValidationErrors(['amount_column']);
        bankMapping(['money_in_column' => 5, 'money_out_column' => 6])->assertUnprocessable()->assertJsonValidationErrors(['amount_column']);
        bankMapping(['amount_column' => null, 'money_in_column' => 5])->assertUnprocessable()->assertJsonValidationErrors(['amount_column']);
        bankMapping(['amount_column' => null, 'money_in_column' => 5, 'money_out_column' => 6])->assertCreated();
    });

    it('accepts only the known delimiters, date layouts and decimal separators', function () {
        bankMapping(['delimiter' => '#'])->assertUnprocessable()->assertJsonValidationErrors(['delimiter']);
        bankMapping(['date_format' => 'Y/d/m'])->assertUnprocessable()->assertJsonValidationErrors(['date_format']);
        bankMapping(['decimal_separator' => ';'])->assertUnprocessable()->assertJsonValidationErrors(['decimal_separator']);
    });
});

describe('importing a statement', function () {
    it('reads the lines with the mapping, keeps the closing balance and the period, and audits the import', function () {
        bankMapping()->assertCreated();

        $statement = importStatement(BANK_CSV, ['closing_balance' => '1485000', 'file_name' => 'maret.csv'])->assertCreated()->json('data');

        expect($statement)->toMatchArray([
            'cash_account_id' => $this->cashAccount, 'file_name' => 'maret.csv', 'period_from' => '2026-03-10', 'period_to' => '2026-03-15',
            'closing_date' => '2026-03-15', 'closing_balance' => '1485000.000000', 'line_count' => 3, 'matched_count' => 0,
        ]);

        $lines = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$statement['id']}/lines")->assertOk()->json('data');

        expect($lines)->toHaveCount(3)
            ->and($lines[0])->toMatchArray(['line_number' => 2, 'transaction_date' => '2026-03-10', 'direction' => 'in', 'amount' => '1500000.000000', 'reference' => 'INV-001', 'cash_transaction_id' => null])
            ->and($lines[1])->toMatchArray(['line_number' => 3, 'direction' => 'out', 'amount' => '25000.000000', 'reference' => null, 'description' => 'Biaya admin'])
            ->and(AuditEntry::where('event', 'bank_statement.imported')->count())->toBe(1);
    });

    it('reads a semicolon file with day-first dates, decimal commas, parentheses for money out, and no header', function () {
        bankMapping(['delimiter' => ';', 'date_format' => 'd/m/Y', 'decimal_separator' => ',', 'has_header' => false, 'lock_version' => null])->assertCreated();

        $csv = "10/03/2026;Setoran;SET-1;1.500.000,50\n12/03/2026;Biaya;;(25.000,25)\n";
        $id = importStatement($csv, ['closing_date' => '2026-03-31'])->assertCreated()->assertJsonPath('data.closing_date', '2026-03-31')->json('data.id');

        $lines = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$id}/lines")->json('data');

        expect($lines[0])->toMatchArray(['line_number' => 1, 'direction' => 'in', 'amount' => '1500000.500000'])
            ->and($lines[1])->toMatchArray(['line_number' => 2, 'direction' => 'out', 'amount' => '25000.250000']);
    });

    it('reads separate money in and money out columns', function () {
        bankMapping(['amount_column' => null, 'money_in_column' => 4, 'money_out_column' => 5])->assertCreated();

        $csv = "date,description,reference,in,out\n2026-03-10,Setoran,A,1000,\n2026-03-11,Biaya,B,,250\n";
        $id = importStatement($csv)->assertCreated()->json('data.id');
        $lines = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$id}/lines")->json('data');

        expect([$lines[0]['direction'], $lines[0]['amount'], $lines[1]['direction'], $lines[1]['amount']])->toBe(['in', '1000.000000', 'out', '250.000000']);

        $both = "date,description,reference,in,out\n2026-03-12,Salah,C,100,100\n2026-03-13,Kosong,D,,\n";
        importStatement($both)->assertUnprocessable()->assertJsonValidationErrors(['rows.2.amount']);
    });

    it('accepts an uploaded file and names the statement after it', function () {
        bankMapping()->assertCreated();

        $file = UploadedFile::fake()->createWithContent('rekening.csv', BANK_CSV);
        $this->actingAs($this->admin)->post("{$this->url}/cash-accounts/{$this->cashAccount}/bank-statements", ['file' => $file, 'closing_balance' => '0'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.file_name', 'rekening.csv')->assertJsonPath('data.line_count', 3);
    });

    it('rejects the whole file and names every bad line when any line is wrong', function () {
        bankMapping()->assertCreated();

        $csv = "date,description,reference,amount\n2026-03-10,Ok,,100\n2026-13-45,Bad date,,100\n2026-03-11,Zero,,0\n2026-03-12,Text,,abc\n2026-03-13,Fine,,50\n";
        importStatement($csv)->assertUnprocessable()->assertJsonValidationErrors(['rows.3.date', 'rows.4.amount', 'rows.5.amount']);

        expect($this->actingAs($this->admin)->getJson("{$this->url}/bank-statements")->json('data'))->toBe([]);
    });

    it('rejects a line with too few columns, an empty file, and a file with a header only', function () {
        bankMapping()->assertCreated();

        importStatement("date,description,reference,amount\n2026-03-10,Short\n")->assertUnprocessable()->assertJsonValidationErrors(['rows.2']);
        importStatement("date,description,reference,amount\n\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
        importStatement(BANK_CSV, ['closing_balance' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['closing_balance']);
    });

    it('refuses an account without a mapping and the same file twice, but takes it into another account', function () {
        importStatement(BANK_CSV)->assertUnprocessable()->assertJsonValidationErrors(['cash_account_id']);

        bankMapping()->assertCreated();
        importStatement(BANK_CSV)->assertCreated();
        importStatement(BANK_CSV)->assertUnprocessable()->assertJsonValidationErrors(['file']);
        importStatement(BANK_CSV."2026-03-20,Lain,,5\n")->assertCreated();
    });

    it('lists the statements newest first, filtered by account, with the number of matched lines', function () {
        bankMapping()->assertCreated();
        $first = importStatement(BANK_CSV, ['closing_date' => '2026-03-31'])->assertCreated()->json('data.id');
        $second = importStatement("date,description,reference,amount\n2026-04-02,X,,10\n", ['closing_date' => '2026-04-30'])->assertCreated()->json('data.id');

        $listed = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements?filter[cash_account_id]={$this->cashAccount}")->assertOk()->json('data');
        expect(array_column($listed, 'id'))->toBe([$second, $first]);

        $none = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements?filter[cash_account_id]=".str_repeat('0', 26))->json('data');
        expect($none)->toBe([]);
    });
});

describe('matching lines to cash transactions', function () {
    beforeEach(function () {
        bankMapping()->assertCreated();
        $this->statementId = importStatement(BANK_CSV)->assertCreated()->json('data.id');
        $this->lines = collect($this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$this->statementId}/lines")->json('data'));
        $this->receiptLine = $this->lines[0]['id'];
        $this->feeLine = $this->lines[1]['id'];
    });

    it('matches a line, clears the transaction on the bank date and audits it', function () {
        $book = bankBook(['transaction_date' => '2026-03-09']);

        matchLine($this->receiptLine, $book->id)->assertOk()->assertJsonPath('data.cash_transaction_id', $book->id);

        $book->refresh();
        expect($book->isCleared())->toBeTrue()->and($book->cleared_on->toDateString())->toBe('2026-03-10')->and((int) $book->cleared_by)->toBe($this->admin->id)
            ->and(AuditEntry::where('event', 'bank_statement_line.matched')->count())->toBe(1)
            ->and($this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$this->statementId}")->json('data.matched_count'))->toBe(1);
    });

    it('clears on the date of the transaction when the bank date is earlier', function () {
        $book = bankBook(['transaction_date' => '2026-03-12']);

        matchLine($this->receiptLine, $book->id)->assertOk();

        expect($book->refresh()->cleared_on->toDateString())->toBe('2026-03-12');
    });

    it('takes back only the cleared mark that the match put', function () {
        $byMatch = bankBook();
        matchLine($this->receiptLine, $byMatch->id)->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/bank-statement-lines/{$this->receiptLine}/unmatch")->assertOk()->assertJsonPath('data.cash_transaction_id', null);
        expect($byMatch->refresh()->isCleared())->toBeFalse();

        $byUser = bankBook(['direction' => 'out', 'amount' => '25000', 'transaction_date' => '2026-03-11', 'reference' => 'FEE']);
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$byUser->id}/clear", ['cleared_on' => '2026-03-11'])->assertOk();
        matchLine($this->feeLine, $byUser->id)->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/bank-statement-lines/{$this->feeLine}/unmatch")->assertOk();

        expect($byUser->refresh()->cleared_on->toDateString())->toBe('2026-03-11')
            ->and(AuditEntry::where('event', 'bank_statement_line.unmatched')->count())->toBe(2);
    });

    it('matches only a transaction of the same direction and amount in the same account', function () {
        $wrongAmount = bankBook(['amount' => '1400000']);
        $wrongDirection = bankBook(['direction' => 'out']);

        matchLine($this->receiptLine, $wrongAmount->id)->assertUnprocessable()->assertJsonValidationErrors(['cash_transaction_id']);
        matchLine($this->receiptLine, $wrongDirection->id)->assertUnprocessable()->assertJsonValidationErrors(['cash_transaction_id']);
        matchLine($this->receiptLine, str_repeat('0', 26))->assertUnprocessable()->assertJsonValidationErrors(['cash_transaction_id']);

        $otherLedger = bankLedgerAccount('1111');
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$otherLedger}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$otherLedger}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
        $other = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
            'name' => 'Kas Kecil', 'kind' => 'cash', 'currency' => 'IDR', 'account_id' => $otherLedger,
        ])->assertCreated()->json('data.id');
        $foreign = bankBook(['cash_account_id' => $other]);
        matchLine($this->receiptLine, $foreign->id)->assertUnprocessable()->assertJsonValidationErrors(['cash_transaction_id']);

        expect(BankStatementLine::query()->withoutGlobalScopes()->whereNotNull('cash_transaction_id')->count())->toBe(0);
    });

    it('holds a match to one line and one transaction', function () {
        $book = bankBook();
        $again = bankBook(['direction' => 'out', 'amount' => '25000', 'reference' => 'FEE']);

        matchLine($this->receiptLine, $book->id)->assertOk();
        matchLine($this->receiptLine, $book->id)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $second = importStatement("date,description,reference,amount\n2026-03-10,Sama,,1500000\n")->assertCreated()->json('data.id');
        $duplicate = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$second}/lines")->json('data.0.id');
        matchLine($duplicate, $book->id)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $this->actingAs($this->admin)->postJson("{$this->url}/bank-statement-lines/{$this->feeLine}/unmatch")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        expect($again->refresh()->isCleared())->toBeFalse();
    });

    it('lists lines by matched state', function () {
        matchLine($this->receiptLine, bankBook()->id)->assertOk();

        $open = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$this->statementId}/lines?filter[matched]=0")->json('data');
        $matched = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$this->statementId}/lines?filter[matched]=1")->json('data');

        expect(array_column($open, 'line_number'))->toBe([3, 4])->and(array_column($matched, 'line_number'))->toBe([2]);
    });

    it('refuses to change anything about a line but its match', function () {
        expect(fn () => DB::table('sl_bank_statement_lines')->where('id', $this->receiptLine)->update(['amount' => '1']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('sl_bank_statement_lines')->where('id', $this->receiptLine)->delete())->toThrow(QueryException::class);
    });
});

describe('suggestions', function () {
    beforeEach(function () {
        bankMapping()->assertCreated();
        $this->statementId = importStatement(BANK_CSV)->assertCreated()->json('data.id');
        $this->lines = collect($this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$this->statementId}/lines")->json('data'));
    });

    it('ranks the candidates of a line by reference and then by the closeness of the date', function () {
        $near = bankBook(['transaction_date' => '2026-03-10', 'reference' => 'OTHER']);
        $far = bankBook(['transaction_date' => '2026-03-14', 'reference' => 'OTHER']);
        $referenced = bankBook(['transaction_date' => '2026-03-16', 'reference' => 'inv-001']);
        bankBook(['transaction_date' => '2026-03-10', 'amount' => '1']);
        bankBook(['transaction_date' => '2026-03-10', 'direction' => 'out']);
        bankBook(['transaction_date' => '2026-04-30', 'reference' => 'LATE']);

        $suggested = suggestionsOf($this->statementId)->assertOk()->json('data');
        $candidates = collect($suggested)->firstWhere('line_id', $this->lines[0]['id'])['candidates'];

        expect(array_column($candidates, 'cash_transaction_id'))->toBe([$referenced->id, $near->id, $far->id])
            ->and($candidates[0])->toMatchArray(['reference_match' => true, 'date_difference_days' => 6])
            ->and($candidates[1])->toMatchArray(['reference_match' => false, 'date_difference_days' => 0]);
    });

    it('widens or narrows the window of dates and leaves out lines without candidates', function () {
        $book = bankBook(['transaction_date' => '2026-03-20', 'reference' => 'X']);

        expect(suggestionsOf($this->statementId)->json('data'))->toBe([]);

        $wide = suggestionsOf($this->statementId, '?window_days=10')->json('data');
        expect($wide)->toHaveCount(1)->and($wide[0]['candidates'][0]['cash_transaction_id'])->toBe($book->id);

        suggestionsOf($this->statementId, '?window_days=-1')->assertUnprocessable();
    });

    it('does not offer a transaction that a line already holds, nor a matched line', function () {
        $book = bankBook();
        $this->actingAs($this->admin)->postJson("{$this->url}/bank-statement-lines/{$this->lines[0]['id']}/match", ['cash_transaction_id' => $book->id])->assertOk();

        $second = importStatement("date,description,reference,amount\n2026-03-10,Sama,INV-001,1500000\n")->assertCreated()->json('data.id');

        expect(suggestionsOf($this->statementId)->json('data'))->toBe([])
            ->and(suggestionsOf($second)->json('data'))->toBe([]);
    });
});

describe('the reconciliation report (SL-AC-09)', function () {
    it('reconciles the book balance with the statement through what is outstanding on each side', function () {
        bankLedgerMovement('1000000');
        bankMapping()->assertCreated();

        // The bank shows a receipt of 1.500.000 that the books hold, a fee of 25.000 and interest of 10.000 that
        // they do not; the books hold a payment of 100.000 that the bank has not shown, and a receipt on 20 March.
        $receipt = bankBook(['amount' => '1500000', 'transaction_date' => '2026-03-10']);
        $payment = bankBook(['direction' => 'out', 'amount' => '100000', 'transaction_date' => '2026-03-14', 'reference' => 'CHQ-9']);
        bankBook(['amount' => '300000', 'transaction_date' => '2026-03-31', 'reference' => 'LATE']);
        bankLedgerMovement('1500000', '2026-03-10');
        bankLedgerMovement('300000', '2026-03-31');
        app(PostingService::class)->post(new PostJournal(
            companyId: $this->companyId, accountingDate: '2026-03-14', description: 'Cheque', source: JournalSource::Subledger,
            lines: [new PostJournalLine($this->salesLedger, '100000', '0'), new PostJournalLine($this->bankLedger, '0', '100000')], postedBy: $this->admin->id,
        ));

        $id = importStatement(BANK_CSV, ['closing_balance' => '2485000', 'closing_date' => '2026-03-15'])->assertCreated()->json('data.id');
        $lines = collect($this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$id}/lines")->json('data'));
        matchLine($lines[0]['id'], $receipt->id)->assertOk();

        $report = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$id}/report")->assertOk()->json('data');

        // Book on 15 March: 1.000.000 + 1.500.000 - 100.000 = 2.400.000 (the receipt of 31 March is later).
        expect($report)->toMatchArray([
            'currency' => 'IDR', 'closing_date' => '2026-03-15', 'statement_balance' => '2485000.000000', 'book_balance' => '2400000.000000',
            'outstanding_in' => '0.000000', 'outstanding_out' => '100000.000000', 'unrecorded_in' => '10000.000000', 'unrecorded_out' => '25000.000000',
            'adjusted_statement_balance' => '2385000.000000', 'adjusted_book_balance' => '2385000.000000', 'difference' => '0.000000', 'reconciled' => true,
        ])->and(array_column($report['outstanding_transactions'], 'cash_transaction_id'))->toBe([$payment->id])
            ->and(array_column($report['unrecorded_lines'], 'line_number'))->toBe([3, 4]);
    });

    it('shows the difference that remains and lists a receipt matched only by a later statement as outstanding', function () {
        bankMapping()->assertCreated();
        $receipt = bankBook(['amount' => '500', 'transaction_date' => '2026-03-10']);
        bankLedgerMovement('500', '2026-03-10');

        $march = importStatement("date,description,reference,amount\n2026-04-02,Setoran,,500\n", ['closing_balance' => '0', 'closing_date' => '2026-03-31'])->assertCreated()->json('data.id');
        $line = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$march}/lines")->json('data.0.id');
        matchLine($line, $receipt->id)->assertOk();

        $report = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$march}/report")->json('data');

        expect($report['outstanding_in'])->toBe('500.000000')->and($report['unrecorded_lines'])->toBe([])
            ->and($report['adjusted_statement_balance'])->toBe('500.000000')->and($report['adjusted_book_balance'])->toBe('500.000000')
            ->and($report['reconciled'])->toBeTrue();

        $off = importStatement("date,description,reference,amount\n2026-03-05,Lain,,70\n", ['closing_balance' => '75', 'closing_date' => '2026-03-31'])->assertCreated()->json('data.id');
        $offReport = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$off}/report")->json('data');

        expect($offReport['unrecorded_in'])->toBe('70.000000')->and($offReport['difference'])->toBe('5.000000')->and($offReport['reconciled'])->toBeFalse();
    });
});

describe('access', function () {
    it('lets a reader see statements and keeps import and matching to who manages them', function () {
        bankMapping()->assertCreated();
        $id = importStatement(BANK_CSV)->assertCreated()->json('data.id');
        $line = $this->actingAs($this->admin)->getJson("{$this->url}/bank-statements/{$id}/lines")->json('data.0.id');
        $book = bankBook();

        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/bank-statements")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/bank-statements/{$id}/report")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/cash-accounts/{$this->cashAccount}/import-mapping")->assertOk();
        $this->actingAs($viewer)->putJson("{$this->url}/cash-accounts/{$this->cashAccount}/import-mapping", [])->assertForbidden();
        importStatement(BANK_CSV."2026-03-20,X,,1\n", as: $viewer)->assertForbidden();
        matchLine($line, $book->id, $viewer)->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/bank-statement-lines/{$line}/unmatch")->assertForbidden();
    });

    it('does not show a statement of another company', function () {
        bankMapping()->assertCreated();
        $id = importStatement(BANK_CSV)->assertCreated()->json('data.id');

        $stranger = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($stranger);

        $this->actingAs($stranger)->getJson("/api/v1/companies/{$otherCompany}/bank-statements/{$id}")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/v1/companies/{$otherCompany}/bank-statements/{$id}/report")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/v1/companies/{$otherCompany}/bank-statements")->assertOk()->assertJsonPath('data', []);
    });
});
