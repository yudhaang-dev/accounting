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

    $this->cash = eqAccountId('1111');
    $this->bank = eqAccountId('1112');
    $this->sales = eqAccountId('4110');
    $this->expense = eqAccountId('6220');
    $this->capital = eqAccountId('3110');
    $this->dividends = eqAccountId('3150');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function eqAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function eqPost(string $date, string $debit, string $credit, string $amount, ?string $key): void
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

/** A company that starts on 1 April with capital of 10,000,000, then earns, takes a deposit and pays a dividend. */
function eqActivity(): void
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

    eqPost('2026-04-05', test()->bank, test()->sales, '1000000.00', 'sales.receipt');
    eqPost('2026-04-10', test()->expense, test()->cash, '300000.00', 'expense.payment');
    eqPost('2026-04-20', test()->cash, test()->capital, '500000.00', 'capital.deposit');
    eqPost('2026-04-28', test()->dividends, test()->cash, '100000.00', 'equity.dividend');
}

/** @param  list<array<string, mixed>>|null  $components */
function eqClassification(?array $components = null): string
{
    $components ??= [
        ['key' => 'capital', 'label' => 'Capital', 'accounts' => [['type' => 'account', 'id' => test()->capital]]],
        ['key' => 'dividends', 'label' => 'Dividends', 'accounts' => [['type' => 'account', 'id' => test()->dividends]]],
        ['key' => 'profit', 'label' => 'Profit', 'accounts' => [['type' => 'class', 'class' => 'income'], ['type' => 'class', 'class' => 'expense']]],
    ];

    return test()->actingAs(test()->admin)->postJson(test()->url.'/movement-classifications', [
        'key' => 'equity', 'name' => 'Equity',
        'structure' => [
            'rows' => [['key' => 'profit_for_year', 'label' => 'Profit'], ['key' => 'contribution', 'label' => 'Contribution'], ['key' => 'distribution', 'label' => 'Distribution']],
            'rules' => [
                ['row' => 'contribution', 'accounts' => [['type' => 'account', 'id' => test()->capital]], 'transaction_type_keys' => ['capital.deposit']],
                ['row' => 'distribution', 'accounts' => [['type' => 'account', 'id' => test()->dividends]], 'transaction_type_keys' => ['equity.dividend']],
                ['row' => 'profit_for_year', 'accounts' => [['type' => 'class', 'class' => 'income'], ['type' => 'class', 'class' => 'expense']], 'transaction_type_keys' => []],
            ],
            'components' => $components,
        ],
    ])->assertCreated()->json('data.id');
}

describe('statement of changes in equity (RP-FR-10, RP-AC-09)', function () {
    it('adds the movement of each component to its opening balance and agrees with the equity of the ledger', function () {
        eqActivity();

        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/".eqClassification().'/equity-changes?from=2026-04-01&to=2026-04-30')->assertOk()->json('data');
        $components = collect($data['components'])->keyBy('key');
        $rows = collect($data['rows'])->keyBy('key');

        expect($components['capital'])->toMatchArray(['opening_balance' => '10000000.00', 'movement' => '500000.00', 'closing_balance' => '10500000.00', 'reconciled' => true])
            ->and($components['dividends'])->toMatchArray(['opening_balance' => '0.00', 'movement' => '-100000.00', 'closing_balance' => '-100000.00', 'reconciled' => true])
            ->and($components['profit'])->toMatchArray(['opening_balance' => '0.00', 'movement' => '700000.00', 'closing_balance' => '700000.00', 'reconciled' => true])
            ->and($rows['profit_for_year']['values'])->toBe(['capital' => '0.00', 'dividends' => '0.00', 'profit' => '700000.00'])
            ->and($rows['contribution']['total'])->toBe('500000.00')
            ->and($rows['distribution']['total'])->toBe('-100000.00')
            ->and($rows)->not->toHaveKey('unclassified')
            ->and($data['components'])->toHaveCount(3)
            ->and($data['equity'])->toBe(['components_total' => '11100000.00', 'ledger_total' => '11100000.00', 'difference' => '0.00', 'reconciled' => true, 'balanced' => true]);
    });

    it('keeps accounts and movement that nothing claims, still counted', function () {
        eqActivity();

        $id = eqClassification([['key' => 'capital', 'label' => 'Capital', 'accounts' => [['type' => 'account', 'id' => $this->capital]]]]);
        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/{$id}/equity-changes?from=2026-04-01&to=2026-04-30")->assertOk()->json('data');
        $components = collect($data['components'])->keyBy('key');

        expect($components['unassigned'])->toMatchArray(['movement' => '600000.00', 'closing_balance' => '600000.00', 'reconciled' => true])
            ->and($data['equity']['balanced'])->toBeTrue()
            ->and($data['equity']['components_total'])->toBe('11100000.00');
    });

    it('shows a component that claims an account outside equity as a difference to the ledger', function () {
        eqActivity();

        $id = eqClassification([['key' => 'odd', 'label' => 'Odd', 'accounts' => [['type' => 'account', 'id' => $this->cash]]]]);
        $data = $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/{$id}/equity-changes?from=2026-04-01&to=2026-04-30")->assertOk()->json('data');

        expect($data['equity']['balanced'])->toBeFalse();
    });

    it('asks for components', function () {
        $id = $this->actingAs($this->admin)->postJson("{$this->url}/movement-classifications", [
            'key' => 'plain', 'name' => 'Plain',
            'structure' => ['rows' => [['key' => 'a', 'label' => 'A']], 'rules' => [['row' => 'a', 'accounts' => [['type' => 'class', 'class' => 'equity']]]]],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/{$id}/equity-changes?from=2026-04-01&to=2026-04-30")
            ->assertUnprocessable()->assertJsonValidationErrors(['components']);
    });

    it('refuses the reserved component key', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/movement-classifications", [
            'key' => 'bad', 'name' => 'Bad',
            'structure' => [
                'rows' => [['key' => 'a', 'label' => 'A']],
                'rules' => [['row' => 'a', 'accounts' => [['type' => 'class', 'class' => 'equity']]]],
                'components' => [['key' => 'unassigned', 'label' => 'X', 'accounts' => [['type' => 'class', 'class' => 'equity']]]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['structure.components.0.key']);
    });
});

describe('CSV export (X-FR-02)', function () {
    it('downloads the equity changes with one column per component', function () {
        eqActivity();

        $response = $this->actingAs($this->viewer)->get("{$this->url}/movement-classifications/".eqClassification().'/equity-changes?from=2026-04-01&to=2026-04-30', ['Accept' => 'text/csv'])
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        expect($response->streamedContent())->toContain('row,label,capital,dividends,profit,total')->toContain('profit_for_year,');
    });
});
