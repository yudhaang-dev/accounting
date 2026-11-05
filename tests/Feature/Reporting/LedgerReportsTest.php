<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->cash = lrAccountId('1111');
    $this->sales = lrAccountId('4110');
    $this->expense = lrAccountId('6220');
    $this->capital = lrAccountId('3110');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function lrAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function lrPost(string $date, string $debit, string $credit, string $amount, ?string $key, ?string $entryId = null): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
        transactionTypeKey: $key,
        entryId: $entryId,
    ));
}

describe('general ledger (RP-FR-06)', function () {
    it('lists the lines of an account with a running balance that starts from the opening balance', function () {
        lrPost('2026-03-10', $this->cash, $this->capital, '1000.00', 'capital.deposit');
        lrPost('2026-04-05', $this->cash, $this->sales, '500.00', 'sales.receipt', 'entry-1');
        lrPost('2026-04-10', $this->expense, $this->cash, '200.00', 'expense.payment');

        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/general-ledger?account_id={$this->cash}&from=2026-04-01&to=2026-04-30")->assertOk()->json('data');

        expect($data['account']['code'])->toBe('1111')
            ->and($data['opening_balance'])->toBe('1000.00')
            ->and(array_column($data['lines'], 'balance'))->toBe(['1500.00', '1300.00'])
            ->and($data['lines'][0])->toMatchArray(['entry_id' => 'entry-1', 'debit' => '500.00', 'credit' => '0.00', 'currency' => 'IDR', 'transaction_type_key' => 'sales.receipt'])
            ->and($data['lines'][0]['journal_id'])->toBeString()
            ->and($data['totals'])->toBe(['debit' => '500.00', 'credit' => '200.00'])
            ->and($data['closing_balance'])->toBe('1300.00');
    });

    it('downloads as Excel and PDF too (ADR-0018)', function () {
        lrPost('2026-04-05', $this->cash, $this->sales, '500.00', 'sales.receipt');
        $url = "{$this->url}/reports/general-ledger?account_id={$this->cash}&from=2026-04-01&to=2026-04-30";
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        $this->actingAs($this->viewer)->get($url, ['Accept' => $xlsx])->assertOk()->assertHeader('content-type', $xlsx);
        expect($this->actingAs($this->viewer)->get($url, ['Accept' => 'application/pdf'])->assertOk()->streamedContent())->toStartWith('%PDF');
    });

    it('requires an account and downloads as CSV', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/general-ledger?from=2026-04-01&to=2026-04-30")->assertUnprocessable()->assertJsonValidationErrors(['account_id']);

        lrPost('2026-04-05', $this->cash, $this->sales, '500.00', 'sales.receipt');

        $this->actingAs($this->viewer)->get("{$this->url}/reports/general-ledger?account_id={$this->cash}&from=2026-04-01&to=2026-04-30", ['Accept' => 'text/csv'])
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });
});

describe('trial balance workpaper (RP-FR-05, RP-AC-11)', function () {
    it('shows before + adjustments = after for every account', function () {
        lrPost('2026-04-05', $this->cash, $this->sales, '1000.00', 'sales.receipt');
        lrPost('2026-04-30', $this->expense, $this->capital, '300.00', 'adjustment.accrual');

        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-04-01&to=2026-04-30&adjustment_keys[]=adjustment.accrual")->assertOk()->json('data');
        $rows = collect($data['workpaper']['rows'])->keyBy('code');

        expect($rows['6220'])->toMatchArray(['before_debit' => '0.00', 'adjustment_debit' => '300.00', 'after_debit' => '300.00'])
            ->and($rows['3110'])->toMatchArray(['before_credit' => '0.00', 'adjustment_credit' => '300.00', 'after_credit' => '300.00'])
            ->and($rows['1111']['before_debit'])->toBe('1000.00')
            ->and($data['workpaper']['balanced'])->toBeTrue();

        foreach ($data['workpaper']['rows'] as $row) {
            expect(bcsub(bcadd($row['before_debit'], $row['adjustment_debit'], 2), bcadd($row['before_credit'], $row['adjustment_credit'], 2), 2))
                ->toBe(bcsub($row['after_debit'], $row['after_credit'], 2));
        }
    });

    it('has no workpaper without adjustment keys', function () {
        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-04-01&to=2026-04-30")->assertOk()->json('data');

        expect($data)->not->toHaveKey('workpaper')->and($data['conversion_batch'])->toBeNull();
    });
});

describe('conversion batch marker (RP-FR-05)', function () {
    it('names the opening balance batch', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", [
            'source_key' => 'legacy-2026-04',
            'source_system' => 'legacy',
            'cutover_date' => '2026-04-01',
            'account_mapping' => [['source_code' => 'A-100', 'account_code' => '1111'], ['source_code' => 'E-300', 'account_code' => '3110']],
            'lines' => [
                ['source_account_code' => 'A-100', 'debit' => '1000.00', 'credit' => '0'],
                ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '1000.00'],
            ],
        ])->assertCreated();

        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-04-01&to=2026-04-30")->assertOk()->json('data');

        expect($data['conversion_batch'])->toMatchArray(['source_key' => 'legacy-2026-04', 'source_system' => 'legacy', 'cutover_date' => '2026-04-01']);
    });
});
