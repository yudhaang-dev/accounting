<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\Subledger\Application\CashTransferPosting;
use App\Modules\Subledger\Contracts\CashEntry;
use App\Modules\Subledger\Contracts\CashTransfer;
use App\Modules\Subledger\Contracts\SubledgerPosting;
use App\Platform\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Cash that moves with no party: the subledger records the cash of the journal (SL-FR-03, SL-FR-11, ADR-0016).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->receivables = docAccountId('1121');
    $this->bank = docAccountId('1112');
    $this->otherBank = docAccountId('1115');
    $this->expense = docAccountId('6220');
    $this->capital = docAccountId('3110');

    foreach ([$this->receivables, $this->bank, $this->otherBank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profileId}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $this->receivables])->assertOk();

    $cash = fn (string $name, string $account) => $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => $name, 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => $name, 'currency' => 'IDR', 'account_id' => $account,
    ])->assertCreated()->json('data.id');
    $this->cashAccount = $cash('IDR-001', $this->bank);
    $this->otherCashAccount = $cash('IDR-002', $this->otherBank);
});

function cashEntry(array $lines, ?string $cashAccountId = null, ?string $reference = null, ?string $entryId = null): CashEntry
{
    return new CashEntry(
        new PostJournal(
            companyId: test()->companyId,
            accountingDate: '2026-03-15',
            description: 'Cash entry',
            source: JournalSource::Subledger,
            lines: $lines,
            entryId: $entryId ?? (string) Str::ulid(),
        ),
        $cashAccountId ?? test()->cashAccount,
        $reference,
    );
}

function cashEntryLine(string $accountId, string $debit, string $credit, ?string $currency = null): PostJournalLine
{
    return new PostJournalLine($accountId, $debit, $credit, currency: $currency);
}

function cashEntryErrors(CashEntry $entry): array
{
    try {
        app(SubledgerPosting::class)->validateCashEntry($entry);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

describe('a cash entry', function () {
    it('records cash out when the lines to the cash account credit it', function () {
        $entry = cashEntry([cashEntryLine($this->expense, '250000', '0'), cashEntryLine($this->bank, '0', '250000')], reference: 'KWT-001');

        $journal = app(SubledgerPosting::class)->postCashEntry($entry);
        $cash = DB::table('sl_cash_transactions')->first();

        expect($cash)->toMatchArray([
            'cash_account_id' => $this->cashAccount, 'direction' => 'out', 'amount' => '250000.000000', 'currency' => 'IDR',
            'reference' => 'KWT-001', 'journal_id' => $journal->id, 'document_id' => null, 'cleared_on' => null,
        ])
            ->and(DB::table('gl_journals')->where('id', $journal->id)->value('source'))->toBe('subledger')
            ->and(DB::table('sl_documents')->count())->toBe(0)
            ->and(DB::table('sl_open_items')->count())->toBe(0);
    });

    it('records cash in when the lines to the cash account debit it, and takes the journal number as the reference by default', function () {
        $journal = app(SubledgerPosting::class)->postCashEntry(cashEntry([cashEntryLine($this->bank, '5000000', '0'), cashEntryLine($this->capital, '0', '5000000')]));

        expect(DB::table('sl_cash_transactions')->first())->toMatchArray(['direction' => 'in', 'amount' => '5000000.000000', 'reference' => $journal->number]);
    });

    it('adds up several lines to the cash account', function () {
        app(SubledgerPosting::class)->postCashEntry(cashEntry([
            cashEntryLine($this->expense, '300000', '0'),
            cashEntryLine($this->bank, '0', '100000'),
            cashEntryLine($this->bank, '0', '200000'),
        ]));

        expect(DB::table('sl_cash_transactions')->count())->toBe(1)
            ->and(DB::table('sl_cash_transactions')->value('amount'))->toBe('300000.000000');
    });

    it('records nothing twice when the same entry is posted again', function () {
        $entry = cashEntry([cashEntryLine($this->expense, '250000', '0'), cashEntryLine($this->bank, '0', '250000')]);

        $first = app(SubledgerPosting::class)->postCashEntry($entry);
        $again = app(SubledgerPosting::class)->postCashEntry($entry);

        expect($again->id)->toBe($first->id)
            ->and(DB::table('sl_cash_transactions')->count())->toBe(1)
            ->and(DB::table('gl_journals')->count())->toBe(1);
    });

    it('is refused when it does not stand on a cash account that exists and is active', function () {
        $lines = [cashEntryLine($this->expense, '1000', '0'), cashEntryLine($this->bank, '0', '1000')];

        expect(cashEntryErrors(cashEntry($lines, '01JZZZZZZZZZZZZZZZZZZZZZZZ')))->toHaveKey('cash_account_id');

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$this->cashAccount}/deactivate")->assertOk();

        expect(cashEntryErrors(cashEntry($lines)))->toHaveKey('cash_account_id');
    });

    it('is refused when a line stands on the wrong side, in the wrong currency, or is missing', function () {
        // Cash out and cash in at once.
        expect(cashEntryErrors(cashEntry([cashEntryLine($this->bank, '1000', '0'), cashEntryLine($this->bank, '0', '400'), cashEntryLine($this->expense, '0', '600')])))->toHaveKey('lines');

        // A line in a currency other than that of the account.
        expect(cashEntryErrors(cashEntry([cashEntryLine($this->expense, '16000', '0'), cashEntryLine($this->bank, '0', '1', 'USD')])))->toHaveKey('lines');

        // No line to the account of the cash account.
        expect(cashEntryErrors(cashEntry([cashEntryLine($this->expense, '1000', '0'), cashEntryLine($this->capital, '0', '1000')])))->toHaveKey('lines');
    });

    it('is refused when the journal touches a control account or another cash account', function () {
        expect(cashEntryErrors(cashEntry([cashEntryLine($this->receivables, '1000', '0'), cashEntryLine($this->bank, '0', '1000')])))->toHaveKey('lines')
            ->and(cashEntryErrors(cashEntry([cashEntryLine($this->otherBank, '1000', '0'), cashEntryLine($this->bank, '0', '1000')])))->toHaveKey('lines');

        expect(fn () => app(SubledgerPosting::class)->postCashEntry(cashEntry([cashEntryLine($this->otherBank, '1000', '0'), cashEntryLine($this->bank, '0', '1000')])))->toThrow(ValidationException::class)
            ->and(DB::table('sl_cash_transactions')->count())->toBe(0)
            ->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('is checked as the ledger checks any journal', function () {
        // An unbalanced journal is refused.
        expect(fn () => app(SubledgerPosting::class)->validateCashEntry(cashEntry([cashEntryLine($this->expense, '1000', '0'), cashEntryLine($this->bank, '0', '900')])))->toThrow(Exception::class);
    });
});

function reverseCashJournal(string $journalId, ?string $entryId = null): ReverseJournal
{
    return new ReverseJournal(test()->companyId, $journalId, '2026-03-20', entryId: $entryId, postedBy: test()->admin->id);
}

describe('the reversal of a cash entry (SL-FR-12)', function () {
    it('answers the cash with a transaction of the opposite direction, so the account follows the ledger', function () {
        $journal = app(SubledgerPosting::class)->postCashEntry(cashEntry([cashEntryLine($this->expense, '250000', '0'), cashEntryLine($this->bank, '0', '250000')]));

        app(SubledgerPosting::class)->validateReversal(reverseCashJournal($journal->id));
        $reversal = app(SubledgerPosting::class)->reverse(reverseCashJournal($journal->id));

        $rows = DB::table('sl_cash_transactions')->orderBy('direction')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows[0])->toMatchArray(['direction' => 'in', 'amount' => '250000.000000', 'journal_id' => $reversal->id, 'document_id' => null, 'transaction_date' => '2026-03-20'])
            ->and($rows[1])->toMatchArray(['direction' => 'out', 'journal_id' => $journal->id])
            ->and(DB::table('gl_journals')->where('id', $reversal->id)->value('reverses_journal_id'))->toBe($journal->id);
    });

    it('is refused a second time, and returns the same reversal for the same key', function () {
        $journal = app(SubledgerPosting::class)->postCashEntry(cashEntry([cashEntryLine($this->bank, '5000000', '0'), cashEntryLine($this->capital, '0', '5000000')]));
        $key = (string) Str::ulid();

        $first = app(SubledgerPosting::class)->reverse(reverseCashJournal($journal->id, $key));
        $again = app(SubledgerPosting::class)->reverse(reverseCashJournal($journal->id, $key));

        expect($again->id)->toBe($first->id)
            ->and(DB::table('sl_cash_transactions')->count())->toBe(2);

        expect(fn () => app(SubledgerPosting::class)->reverse(reverseCashJournal($journal->id)))->toThrow(ApiException::class)
            ->and(fn () => app(SubledgerPosting::class)->validateReversal(reverseCashJournal($journal->id)))->toThrow(ApiException::class)
            ->and(DB::table('sl_cash_transactions')->count())->toBe(2);
    });

    it('gives back both legs of a transfer', function () {
        $journal = app(CashTransferPosting::class)->post(new CashTransfer(
            new PostJournal(companyId: $this->companyId, accountingDate: '2026-03-15', description: 'Transfer', source: JournalSource::Subledger,
                lines: [cashEntryLine($this->otherBank, '1000000', '0'), cashEntryLine($this->bank, '0', '1000000')], entryId: (string) Str::ulid()),
            $this->cashAccount,
            $this->otherCashAccount,
        ));

        $reversal = app(SubledgerPosting::class)->reverse(reverseCashJournal($journal->id));

        $balance = fn (string $account) => DB::table('sl_cash_transactions')->where('cash_account_id', $account)->get()
            ->sum(fn ($row) => $row->direction === 'in' ? (float) $row->amount : -(float) $row->amount);

        expect(DB::table('sl_cash_transactions')->where('journal_id', $reversal->id)->count())->toBe(2)
            ->and($balance($this->cashAccount))->toBe(0.0)
            ->and($balance($this->otherCashAccount))->toBe(0.0);
    });

    it('is still refused for a journal that has no cash and no document', function () {
        $plain = app(PostingService::class)->post(new PostJournal(companyId: $this->companyId, accountingDate: '2026-03-15', description: 'Plain', source: JournalSource::Entry,
            lines: [cashEntryLine($this->expense, '1000', '0'), cashEntryLine($this->capital, '0', '1000')], entryId: (string) Str::ulid()));

        expect(fn () => app(SubledgerPosting::class)->reverse(reverseCashJournal($plain->id)))->toThrow(ApiException::class);
    });
});
