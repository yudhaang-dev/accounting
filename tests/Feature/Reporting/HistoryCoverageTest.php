<?php

use App\Models\User;
use App\Modules\Reporting\Application\HistoryCoverage;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);
});

/** Balances at 1 April; with a cutover on 1 June, April and May are history. */
function hcOpening(string $start, string $cutover): void
{
    test()->actingAs(test()->admin)->postJson(test()->url.'/opening-balance-imports', [
        'source_key' => 'legacy-opening',
        'cutover_date' => $cutover,
        'ledger_start_date' => $start,
        'account_mapping' => [
            ['source_code' => 'A-100', 'account_code' => '1111'],
            ['source_code' => 'E-300', 'account_code' => '3110'],
            ['source_code' => 'I-400', 'account_code' => '4110'],
        ],
        'lines' => [
            ['source_account_code' => 'A-100', 'debit' => '12000000.00', 'credit' => '0'],
            ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '10000000.00'],
            ['source_account_code' => 'I-400', 'debit' => '0', 'credit' => '2000000.00'],
        ],
    ])->assertCreated();
}

function hcDefinition(): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/report-definitions', [
        'key' => 'coverage', 'name' => 'Coverage', 'kind' => 'custom',
        'structure' => [
            'basis' => 'movement',
            'rows' => [['key' => 'revenue', 'label' => 'Revenue', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'income']]]],
            'columns' => [
                ['key' => 'month', 'label' => 'Month', 'kind' => 'period'],
                ['key' => 'ytd', 'label' => 'YTD', 'kind' => 'ytd'],
                ['key' => 'previous', 'label' => 'Previous month', 'kind' => 'period', 'shift' => ['years' => 0, 'months' => -1]],
                ['key' => 'change', 'label' => 'Change', 'kind' => 'difference', 'minuend' => 'month', 'subtrahend' => 'previous'],
            ],
        ],
    ])->assertCreated()->json('data.id');
}

/** @return array<string, mixed> */
function hcRun(string $id, string $from, string $to): array
{
    return test()->actingAs(test()->admin)->getJson(test()->url."/report-definitions/{$id}/result?from={$from}&to={$to}")->assertOk()->json('data');
}

describe('history coverage (RP-FR-13)', function () {
    it('covers all of a company that started in the system', function () {
        $result = hcRun(hcDefinition(), '2026-03-01', '2026-03-31');

        expect($result)->toMatchArray(['coverage_from' => '2026-01-01', 'coverage_to' => '2026-03-31', 'coverage_granularity' => 'transactions', 'coverage_status' => 'complete']);
    });

    it('marks what lies before the ledger start date unavailable rather than zero (RP-AC-10)', function () {
        hcOpening('2026-06-01', '2026-06-01');
        $id = hcDefinition();

        $june = hcRun($id, '2026-06-01', '2026-06-30');
        $columns = collect($june['columns'])->keyBy('key');

        // A plain report keeps the import in the month. The previous month, May, is before the ledger start, so it and the change from it have no value.
        expect($june['coverage_status'])->toBe('partial')
            ->and($columns['month']['coverage_status'])->toBe('complete')
            ->and($columns['previous'])->toMatchArray(['available' => false, 'coverage_status' => 'unavailable'])
            ->and($columns['change'])->toMatchArray(['available' => false, 'coverage_status' => 'unavailable'])
            ->and($june['rows'][0]['values'])->toBe(['month' => '2000000.00', 'ytd' => '2000000.00', 'previous' => null, 'change' => null]);

        $march = hcRun($id, '2026-03-01', '2026-03-31');

        expect($march['coverage_status'])->toBe('unavailable')
            ->and($march['coverage_from'])->toBeNull()
            ->and($march['rows'][0]['values']['month'])->toBeNull();

        $this->actingAs($this->admin)->getJson("{$this->url}/reports/trial-balance?from=2026-03-01&to=2026-03-31")->assertOk()
            ->assertJsonPath('data.coverage_status', 'unavailable');
        $this->actingAs($this->admin)->getJson("{$this->url}/reports/trial-balance?from=2026-05-15&to=2026-06-30")->assertOk()
            ->assertJsonPath('data.coverage_status', 'partial')
            ->assertJsonPath('data.coverage_from', '2026-06-01');
    });

    it('covers the months of an imported history in its granularity', function () {
        hcOpening('2026-04-01', '2026-06-01');
        $this->actingAs($this->admin)->postJson("{$this->url}/history-imports", [
            'source_key' => 'legacy-history',
            'granularity' => 'period_summary',
            'journals' => [['accounting_date' => '2026-04-30', 'description' => 'April', 'lines' => [
                ['source_account_code' => 'A-100', 'debit' => '500000.00', 'credit' => '0'],
                ['source_account_code' => 'I-400', 'debit' => '0', 'credit' => '500000.00'],
            ]]],
            'cutover_balances' => [
                ['source_account_code' => 'A-100', 'debit' => '12500000.00', 'credit' => '0'],
                ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '10000000.00'],
                ['source_account_code' => 'I-400', 'debit' => '0', 'credit' => '2500000.00'],
            ],
        ])->assertCreated();

        $id = hcDefinition();
        $may = hcRun($id, '2026-05-01', '2026-05-31');
        $columns = collect($may['columns'])->keyBy('key');

        expect($may)->toMatchArray(['coverage_status' => 'complete', 'coverage_granularity' => 'period_summary'])
            ->and($columns['previous']['coverage_status'])->toBe('complete')
            ->and($may['rows'][0]['values']['previous'])->toBe('2500000.00');

        // A balance carries what came before it, so a year to date column is whole, but February is not there.
        $march = hcRun($id, '2026-03-01', '2026-03-31');
        expect($march['coverage_status'])->toBe('unavailable');

        $coverage = app(HistoryCoverage::class);

        // A report that needs the Jenis Transaksi of each journal cannot use a summary per period.
        expect($coverage->assess($this->companyId, '2026-04-01', '2026-05-31')['coverage_status'])->toBe('complete')
            ->and($coverage->assess($this->companyId, '2026-04-01', '2026-05-31', classified: true)['coverage_status'])->toBe('unavailable')
            ->and($coverage->assess($this->companyId, '2026-05-01', '2026-06-30', classified: true))->toMatchArray(['coverage_status' => 'partial', 'coverage_from' => '2026-06-01', 'coverage_granularity' => 'transactions']);
    });
});
