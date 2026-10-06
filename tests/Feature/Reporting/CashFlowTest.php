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

    $this->cash = cfAccountId('1111');
    $this->bank = cfAccountId('1112');
    $this->sales = cfAccountId('4110');
    $this->expense = cfAccountId('6220');
    $this->capital = cfAccountId('3110');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function cfAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function cfPost(string $date, string $debit, string $credit, string $amount, ?string $key): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
        transactionTypeKey: $key,
    ));
}

function cfMapCash(): void
{
    test()->actingAs(test()->admin)->putJson(test()->url.'/reporting/account-role-mappings/cash_equivalents', ['lock_version' => 0, 'account_ids' => [test()->cash, test()->bank]])->assertOk();
}

/** A company that starts on 1 April with 10,000,000 of cash against capital, then trades in April. */
function cfActivity(): void
{
    test()->actingAs(test()->admin)->postJson(test()->url.'/opening-balance-imports', [
        'source_key' => 'legacy-2026-04',
        'source_system' => 'legacy',
        'cutover_date' => '2026-04-01',
        'account_mapping' => [['source_code' => 'A-100', 'account_code' => '1111'], ['source_code' => 'E-300', 'account_code' => '3110']],
        'lines' => [
            ['source_account_code' => 'A-100', 'debit' => '10000000.00', 'credit' => '0'],
            ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '10000000.00'],
        ],
    ])->assertCreated();

    cfPost('2026-04-05', test()->bank, test()->sales, '1000000.00', 'sales.receipt');
    cfPost('2026-04-10', test()->expense, test()->cash, '300000.00', 'expense.payment');
    cfPost('2026-04-15', test()->bank, test()->cash, '200000.00', 'cash.transfer');
    cfPost('2026-04-20', test()->cash, test()->capital, '500000.00', 'capital.deposit');
    cfPost('2026-04-25', test()->bank, test()->capital, '80000.00', 'other');
}

function cfClassification()
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/movement-classifications', [
        'key' => 'cash_flow', 'name' => 'Cash flow',
        'structure' => [
            'rows' => [['key' => 'operating', 'label' => 'Operating'], ['key' => 'financing', 'label' => 'Financing']],
            'rules' => [
                ['row' => 'financing', 'accounts' => [['type' => 'account', 'id' => test()->capital]], 'transaction_type_keys' => ['capital.deposit']],
                ['row' => 'operating', 'accounts' => [['type' => 'class', 'class' => 'income'], ['type' => 'class', 'class' => 'expense']], 'transaction_type_keys' => []],
            ],
        ],
    ])->assertCreated()->json('data.id');
}

/** @return array<string, array<string, string|null>> */
function cfIndirect(bool $withCapital = true): array
{
    $rows = [
        ['key' => 'profit', 'label' => 'Profit', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'income'], ['type' => 'class', 'class' => 'expense']]],
    ];

    if ($withCapital) {
        $rows[] = ['key' => 'capital', 'label' => 'Capital', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'equity']]];
    }

    $rows[] = ['key' => 'net_increase', 'label' => 'Net increase', 'type' => 'subtotal', 'terms' => array_map(fn (array $row): array => ['row' => $row['key'], 'sign' => 1], $rows)];
    $rows[] = ['key' => 'cash_opening', 'label' => 'Opening cash', 'type' => 'cash_equivalents', 'basis' => 'opening'];
    $rows[] = ['key' => 'cash_change', 'label' => 'Change in cash', 'type' => 'cash_equivalents', 'basis' => 'movement'];
    $rows[] = ['key' => 'cash_closing', 'label' => 'Closing cash', 'type' => 'cash_equivalents', 'basis' => 'closing'];

    $id = test()->actingAs(test()->admin)->postJson(test()->url.'/report-definitions', [
        'key' => 'cash_flow_indirect', 'name' => 'Cash flow', 'kind' => 'cash_flow_indirect',
        'structure' => ['basis' => 'movement', 'rows' => $rows, 'columns' => [['key' => 'month', 'label' => 'Month', 'kind' => 'period']]],
    ])->assertCreated()->json('data.id');

    return test()->actingAs(test()->viewer)->getJson(test()->url."/report-definitions/{$id}/result?from=2026-04-01&to=2026-04-30")->assertOk()->json('data');
}

describe('direct method (RP-FR-09, RP-AC-02)', function () {
    it('classifies what cash was paid for, nets a transfer to zero and reconciles the cash accounts', function () {
        cfMapCash();
        cfActivity();

        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/".cfClassification().'/cash-flow?from=2026-04-01&to=2026-04-30')->assertOk()->json('data');
        $rows = collect($data['rows'])->keyBy('key');

        expect($rows['operating'])->toMatchArray(['inflow' => '1000000.00', 'outflow' => '300000.00', 'net' => '700000.00'])
            ->and($rows['financing'])->toMatchArray(['inflow' => '500000.00', 'outflow' => '0.00', 'net' => '500000.00'])
            ->and($rows['unclassified']['net'])->toBe('80000.00')
            ->and($data['unclassified'])->toHaveCount(1)
            ->and($data['cash'])->toBe([
                'opening_balance' => '0.00',
                'imported_opening_balance' => '10000000.00',
                'net_increase' => '1280000.00',
                'closing_balance' => '11280000.00',
                'difference' => '0.00',
                'reconciled' => true,
            ])
            ->and($data['mapping']['problems'])->toBe([]);
    });

    it('asks for the cash_equivalents mapping', function () {
        $id = cfClassification();

        $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/{$id}/cash-flow?from=2026-04-01&to=2026-04-30")
            ->assertUnprocessable()->assertJsonValidationErrors(['cash_equivalents']);
    });
});

describe('indirect method (RP-FR-09, RP-AC-02)', function () {
    it('adjusts profit with the other movements and agrees with the change in cash', function () {
        cfMapCash();
        cfActivity();

        $data = cfIndirect();
        $rows = collect($data['rows'])->mapWithKeys(fn (array $row): array => [$row['key'] => $row['values']['month']]);

        expect($rows['profit'])->toBe('700000.00')
            ->and($rows['capital'])->toBe('580000.00')
            ->and($rows['net_increase'])->toBe('1280000.00')
            ->and($rows['cash_opening'])->toBe('10000000.00')
            ->and($rows['cash_change'])->toBe($rows['net_increase'])
            ->and($rows['cash_closing'])->toBe('11280000.00')
            ->and($rows)->not->toHaveKey('unmapped')
            ->and($data['cash_checks']['month'])->toBe(['opening_balance' => '10000000.00', 'net_increase' => '1280000.00', 'closing_balance' => '11280000.00', 'reconciled' => true]);
    });

    it('shows movement that no row claims in the unmapped row, still counted', function () {
        cfMapCash();
        cfActivity();

        $data = cfIndirect(withCapital: false);
        $rows = collect($data['rows'])->mapWithKeys(fn (array $row): array => [$row['key'] => $row['values']['month']]);

        expect($rows['net_increase'])->toBe('700000.00')
            ->and($rows['unmapped'])->toBe('-580000.00')
            ->and(collect($data['unmapped_accounts'])->pluck('code')->all())->toBe(['3110']);
    });

    it('asks for the cash_equivalents mapping', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [
            'key' => 'cash_flow_indirect', 'name' => 'Cash flow', 'kind' => 'cash_flow_indirect',
            'structure' => ['rows' => [['key' => 'cash', 'label' => 'Cash', 'type' => 'cash_equivalents']], 'columns' => [['key' => 'month', 'label' => 'Month', 'kind' => 'period']]],
        ])->assertCreated();

        $id = $this->actingAs($this->admin)->getJson("{$this->url}/report-definitions")->json('data.0.id');

        $this->actingAs($this->viewer)->getJson("{$this->url}/report-definitions/{$id}/result?from=2026-04-01&to=2026-04-30")
            ->assertUnprocessable()->assertJsonValidationErrors(['cash_equivalents']);
    });
});
