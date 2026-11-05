<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Reporting\Application\ReportEngine;
use App\Support\Company\CompanyScope;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = adAccountId('1112');
    $this->cash = adAccountId('1111');
    $this->goods = adAccountId('4110');
    $this->services = adAccountId('4120');
    $this->cost = adAccountId('5110');
});

function adAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function adPost(string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
    ));
}

/** @return array<string, mixed> */
function adRun(bool $detail): array
{
    $structure = [
        'basis' => 'movement',
        'rows' => [
            ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'accounts', 'sign' => 'credit', 'basis' => 'movement', 'accounts' => [['type' => 'class', 'class' => 'income']], ...($detail ? ['account_detail' => true] : [])],
            ['key' => 'costs', 'label' => 'Costs', 'type' => 'accounts', 'sign' => 'debit', 'basis' => 'movement', 'accounts' => [['type' => 'class', 'class' => 'expense']]],
            ['key' => 'cash', 'label' => 'Cash', 'type' => 'accounts', 'sign' => 'debit', 'basis' => 'closing', 'accounts' => [['type' => 'account', 'id' => test()->cash], ['type' => 'account', 'id' => test()->bank]], ...($detail ? ['account_detail' => true] : [])],
        ],
        'columns' => [
            ['key' => 'month', 'label' => 'Month', 'kind' => 'period', 'shift' => ['years' => 0, 'months' => 0]],
            ['key' => 'previous', 'label' => 'Previous', 'kind' => 'period', 'shift' => ['years' => 0, 'months' => -1]],
            ['key' => 'change', 'label' => 'Change', 'kind' => 'difference', 'minuend' => 'month', 'subtrahend' => 'previous'],
        ],
    ];

    return app(ReportEngine::class)(test()->companyId, $structure, '2026-02-01', '2026-02-28');
}

/** @return array<string, array<string, mixed>> */
function adRows(array $result): array
{
    return collect($result['rows'])->keyBy('key')->all();
}

describe('account detail of a report row (RP-FR-01, RP-AC-12)', function () {
    it('lists the accounts of a detailed row in code order, and the accounts add up to the row in every column', function () {
        adPost('2026-01-10', $this->bank, $this->services, '300000.00');
        adPost('2026-02-10', $this->bank, $this->goods, '500000.00');
        adPost('2026-02-12', $this->cash, $this->services, '200000.00');
        adPost('2026-02-15', $this->cost, $this->cash, '100000.00');

        $rows = adRows(adRun(true));
        $lines = collect($rows['revenue']['account_lines']);
        $leaves = $lines->where('is_leaf', true)->values();

        expect($leaves->pluck('code')->all())->toBe(['4110', '4120'])
            ->and($leaves->pluck('values')->all())->toBe([
                ['month' => '500000.00', 'previous' => '0.00', 'change' => '500000.00'],
                ['month' => '200000.00', 'previous' => '300000.00', 'change' => '-100000.00'],
            ]);

        foreach (['month', 'previous', 'change'] as $column) {
            $sum = $leaves->sum(fn (array $line): float => (float) $line['values'][$column]);

            expect(number_format($sum, 2, '.', ''))->toBe($rows['revenue']['values'][$column]);
        }

        // Every parent heading adds up what is below it, and a child is one level deeper than its parent.
        $heading = $lines->firstWhere('is_leaf', false);
        expect($heading)->not->toBeNull()
            ->and($heading['level'])->toBe(0)
            ->and($heading['values']['month'])->toBe('700000.00')
            ->and($leaves->first()['level'])->toBeGreaterThan($heading['level']);
    });

    it('matches the trial balance for the same range', function () {
        adPost('2026-02-10', $this->bank, $this->goods, '500000.00');
        adPost('2026-02-12', $this->cash, $this->services, '200000.00');

        $trial = collect($this->actingAs($this->admin)->getJson("{$this->url}/reports/trial-balance?from=2026-02-01&to=2026-02-28")->assertOk()->json('data.rows'))->keyBy('code');
        $leaves = collect(adRows(adRun(true))['revenue']['account_lines'])->where('is_leaf', true)->keyBy('code');

        foreach (['4110', '4120'] as $code) {
            expect($leaves[$code]['values']['month'])->toBe($trial[$code]['credit']);
        }
    });

    it('applies the basis and sign of the row to its accounts', function () {
        adPost('2026-01-10', $this->bank, $this->goods, '300000.00');
        adPost('2026-02-12', $this->cash, $this->services, '200000.00');

        $cash = collect(adRows(adRun(true))['cash']['account_lines']);

        // A closing balance of debit accounts: the bank holds January's money as well.
        expect($cash->where('code', '1112')->first()['values']['month'])->toBe('300000.00')
            ->and($cash->where('code', '1111')->first()['values']['month'])->toBe('200000.00')
            ->and($cash->where('code', '1112')->first()['values']['previous'])->toBe('300000.00');
    });

    it('leaves out an account that is zero in every column', function () {
        adPost('2026-02-10', $this->bank, $this->goods, '500000.00');

        $codes = collect(adRows(adRun(true))['revenue']['account_lines'])->pluck('code');

        expect($codes)->toContain('4110')->and($codes)->not->toContain('4120');
    });

    it('gives no lines to a row that does not ask for them, and the same values with or without', function () {
        adPost('2026-02-10', $this->bank, $this->goods, '500000.00');
        adPost('2026-02-15', $this->cost, $this->bank, '100000.00');

        $detailed = adRows(adRun(true));
        $plain = adRows(adRun(false));

        expect($plain['revenue'])->not->toHaveKey('account_lines')
            ->and($detailed['costs'])->not->toHaveKey('account_lines')
            ->and($detailed['revenue']['values'])->toBe($plain['revenue']['values'])
            ->and($detailed['costs']['values'])->toBe($plain['costs']['values']);
    });
});

/** @return array<string, mixed> a definition of revenue and costs for February */
function adStructure(bool $detail): array
{
    return [
        'basis' => 'movement',
        'rows' => [
            ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'income']], ...($detail ? ['account_detail' => true] : [])],
        ],
        'columns' => [['key' => 'month', 'label' => 'Month', 'kind' => 'period']],
    ];
}

describe('account detail in the definition (RP-FR-01)', function () {
    it('is saved with the structure, off unless asked for, and refuses anything but a boolean', function () {
        $body = ['key' => 'revenue_report', 'name' => 'Revenue', 'structure' => adStructure(true)];

        $created = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", $body)->assertCreated()->json('data');
        expect($created['structure']['rows'][0]['account_detail'])->toBeTrue();

        $plain = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [...$body, 'key' => 'plain', 'structure' => adStructure(false)])->assertCreated()->json('data');
        expect($plain['structure']['rows'][0])->not->toHaveKey('account_detail');

        $wrong = adStructure(false);
        $wrong['rows'][0]['account_detail'] = 'yes';
        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [...$body, 'key' => 'wrong', 'structure' => $wrong])
            ->assertUnprocessable()->assertJsonValidationErrors(['structure.rows.0.account_detail']);

        // It belongs to rows that list accounts only.
        $heading = adStructure(false);
        $heading['rows'][] = ['key' => 'title', 'label' => 'Title', 'type' => 'heading', 'account_detail' => true];
        $saved = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [...$body, 'key' => 'heading', 'structure' => $heading])->assertCreated()->json('data');
        expect($saved['structure']['rows'][1])->not->toHaveKey('account_detail');
    });

    it('turns on in a new version, leaves the old version and its snapshot without lines, and keeps lines in a new snapshot (RP-AC-08)', function () {
        adPost('2026-02-10', $this->bank, $this->goods, '500000.00');

        $created = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", ['key' => 'revenue_report', 'name' => 'Revenue', 'structure' => adStructure(false)])->assertCreated()->json('data');
        $id = $created['id'];
        $period = 'from=2026-02-01&to=2026-02-28';

        $old = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions/{$id}/snapshots", ['from' => '2026-02-01', 'to' => '2026-02-28'])->assertCreated()->json('data');

        $updated = $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$id}", ['structure' => adStructure(true), 'lock_version' => $created['lock_version']])->assertOk()->json('data');
        expect($updated['current_version'])->toBe(2);

        $first = $this->actingAs($this->admin)->getJson("{$this->url}/report-definitions/{$id}/result?{$period}&version=1")->assertOk()->json('data.rows.0');
        $second = $this->actingAs($this->admin)->getJson("{$this->url}/report-definitions/{$id}/result?{$period}")->assertOk()->json('data.rows.0');

        expect($first)->not->toHaveKey('account_lines')
            ->and($second['account_lines'])->not->toBeEmpty()
            ->and($second['values'])->toBe($first['values']);

        $oldRead = $this->actingAs($this->admin)->getJson("{$this->url}/report-snapshots/{$old['id']}")->assertOk()->json('data');
        expect($oldRead['result']['rows'][0])->not->toHaveKey('account_lines');

        $new = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions/{$id}/snapshots", ['from' => '2026-02-01', 'to' => '2026-02-28'])->assertCreated()->json('data');
        expect($new['result']['rows'][0]['account_lines'])->toEqual($second['account_lines']);
    });

    it('follows a detailed row in the CSV with its accounts, typed apart from the rows (X-FR-02)', function () {
        adPost('2026-02-10', $this->bank, $this->goods, '500000.00');
        adPost('2026-02-12', $this->cash, $this->services, '200000.00');

        $id = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", ['key' => 'revenue_report', 'name' => 'Revenue', 'structure' => adStructure(true)])->assertCreated()->json('data.id');

        $csv = $this->actingAs($this->admin)->get("{$this->url}/report-definitions/{$id}/result?from=2026-02-01&to=2026-02-28", ['Accept' => 'text/csv'])
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();

        expect($csv)->toContain('row,label,type,month')
            ->toContain('revenue,Revenue,accounts,700000.00')
            ->toContain('revenue:4110,')->toContain(',account,500000.00')
            ->toContain('revenue:4120,')->toContain(',account,200000.00');
    });

    it('downloads the statement as Excel and PDF with the accounts under their row, and refuses one over the row limit (X-FR-02, ADR-0018)', function () {
        adPost('2026-02-10', $this->bank, $this->goods, '500000.00');
        adPost('2026-02-12', $this->cash, $this->services, '200000.00');

        $id = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", ['key' => 'revenue_report', 'name' => 'Revenue', 'structure' => adStructure(true)])->assertCreated()->json('data.id');
        $url = "{$this->url}/report-definitions/{$id}/result?from=2026-02-01&to=2026-02-28";
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        $content = $this->actingAs($this->admin)->get($url, ['Accept' => $xlsx])->assertOk()->assertHeader('content-type', $xlsx)->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        expect($sheet)->toContain('Revenue')->toContain('4110')->toContain('4120')
            ->toContain('<v>700000</v>')->toContain('<v>500000</v>')->toContain('<v>200000</v>');

        $pdf = $this->actingAs($this->admin)->get($url, ['Accept' => 'application/pdf'])->assertOk()->assertHeader('content-type', 'application/pdf')->streamedContent();
        expect($pdf)->toStartWith('%PDF');

        config(['export.max_rows' => 2]);
        $this->actingAs($this->admin)->get($url, ['Accept' => 'application/pdf'])->assertUnprocessable()->assertJsonPath('code', 'EXPORT_TOO_LARGE');
        $this->actingAs($this->admin)->get($url, ['Accept' => 'text/csv'])->assertOk();
    });

    it('is on for the account rows of the template statements, and for the cash rows only of the indirect cash flow', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions/import-template", ['template' => 'general-id', 'confirmed' => true, 'keys' => ['income_statement', 'balance_sheet', 'cash_flow_indirect']])->assertCreated();

        $definitions = collect($this->actingAs($this->admin)->getJson("{$this->url}/report-definitions")->json('data'))->keyBy('key');
        $detailed = fn (string $key): array => collect($this->actingAs($this->admin)->getJson("{$this->url}/report-definitions/{$definitions[$key]['id']}")->json('data.structure.rows'))
            ->filter(fn (array $row): bool => ($row['account_detail'] ?? false) === true)->pluck('key')->all();

        expect($detailed('income_statement'))->toBe(['revenue', 'revenue_deductions', 'cost_of_sales', 'operating_expenses', 'other_income', 'other_expenses', 'income_tax'])
            ->and($detailed('balance_sheet'))->toBe(['current_assets', 'non_current_assets', 'current_liabilities', 'non_current_liabilities', 'equity'])
            ->and($detailed('cash_flow_indirect'))->toBe(['cash_opening', 'cash_closing']);
    });
});
