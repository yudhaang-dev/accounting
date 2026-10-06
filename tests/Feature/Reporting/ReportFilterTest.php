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

    $this->bank = rfAccountId('1112');
    $this->sales = rfAccountId('4110');

    $department = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types", ['key' => 'region', 'label' => 'Region', 'is_hierarchical' => true])->assertCreated()->json('data.id');
    $this->west = rfValue($department, 'WEST', 'West');
    $this->jakarta = rfValue($department, 'JKT', 'Jakarta', $this->west);
    $this->bandung = rfValue($department, 'BDG', 'Bandung', $this->west);
    $this->east = rfValue($department, 'EAST', 'East');

    rfPost('2026-02-05', '100.00', ['region' => $this->jakarta], JournalSource::Entry, 'sales.invoice');
    rfPost('2026-02-06', '200.00', ['region' => $this->bandung], JournalSource::Entry, 'sales.invoice');
    rfPost('2026-02-07', '400.00', ['region' => $this->east], JournalSource::Subledger, 'sales.cash');
    rfPost('2026-02-08', '800.00', null, JournalSource::System, 'system.accrual');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function rfAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function rfValue(string $typeId, string $code, string $name, ?string $parentId = null): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-values', ['dimension_type_id' => $typeId, 'code' => $code, 'name' => $name, 'parent_id' => $parentId])->assertCreated()->json('data.id');
}

/** @param  array<string, string>|null  $dimensions */
function rfPost(string $date, string $amount, ?array $dimensions, JournalSource $source, string $key): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Test',
        source: $source,
        lines: [new PostJournalLine(test()->bank, $amount, '0'), new PostJournalLine(test()->sales, '0', $amount, dimensions: $dimensions)],
        transactionTypeKey: $key,
    ));
}

function rfTrialBalance(string $query)
{
    return test()->actingAs(test()->viewer)->getJson(test()->url.'/reports/trial-balance?from=2026-02-01&to=2026-02-28&account_id='.test()->sales.$query);
}

function rfCredit(array $response): string
{
    return collect($response['data']['rows'])->firstWhere('code', '4110')['credit'];
}

describe('report filters (RP-FR-04)', function () {
    it('counts lines with and without dimensions when no dimension filter is given (RP-AC-03)', function () {
        expect(rfCredit(rfTrialBalance('')->assertOk()->json()))->toBe('1500.00');
    });

    it('keeps only the selected dimension value, and a parent value includes its descendants (RP-AC-03)', function () {
        expect(rfCredit(rfTrialBalance("&dimensions[region]={$this->jakarta}")->assertOk()->json()))->toBe('100.00')
            ->and(rfCredit(rfTrialBalance("&dimensions[region]={$this->west}")->assertOk()->json()))->toBe('300.00')
            ->and(rfCredit(rfTrialBalance("&dimensions[region]={$this->east}")->assertOk()->json()))->toBe('400.00');
    });

    it('filters by transaction type key and by source', function () {
        $invoices = rfTrialBalance('&transaction_type_keys[]=sales.invoice')->assertOk();
        $both = rfTrialBalance('&transaction_type_keys[]=sales.invoice&transaction_type_keys[]=sales.cash')->assertOk();
        $system = rfTrialBalance('&source=system')->assertOk();

        expect(rfCredit($invoices->json()))->toBe('300.00')
            ->and(rfCredit($both->json()))->toBe('700.00')
            ->and(rfCredit($system->json()))->toBe('800.00')
            ->and($invoices->json('data.parameters.transaction_type_keys'))->toBe(['sales.invoice'])
            ->and($system->json('data.parameters.source'))->toBe('system');
    });

    it('combines the filters and keeps the opening balance inside them', function () {
        $response = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-02-07&to=2026-02-28&account_id={$this->sales}&dimensions[region]={$this->west}&source=entry")->assertOk();

        $row = collect($response->json('data.rows'))->firstWhere('code', '4110');

        expect($row['opening_credit'])->toBe('300.00')
            ->and($row['credit'])->toBe('0.00');
    });

    it('returns nothing for a key that no journal has', function () {
        expect(rfTrialBalance('&transaction_type_keys[]=unknown.key')->assertOk()->json('data.rows'))->toBe([]);
    });

    it('refuses an unknown source and a malformed key', function () {
        rfTrialBalance('&source=manual')->assertUnprocessable()->assertJsonValidationErrors('source');
        rfTrialBalance('&transaction_type_keys[]=Bad Key')->assertUnprocessable();
    });

    it('applies the same filters to every column of a report definition', function () {
        $structure = [
            'basis' => 'movement',
            'rows' => [['key' => 'revenue', 'label' => 'Revenue', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'account', 'id' => $this->sales]]]],
            'columns' => [['key' => 'month', 'label' => 'Month', 'kind' => 'period'], ['key' => 'ytd', 'label' => 'YTD', 'kind' => 'ytd']],
        ];
        $id = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", ['key' => 'revenue', 'name' => 'Revenue', 'structure' => $structure])->assertCreated()->json('data.id');
        $run = fn (string $query) => $this->actingAs($this->viewer)->getJson("{$this->url}/report-definitions/{$id}/result?from=2026-02-01&to=2026-02-28{$query}")->assertOk();

        $all = $run('');
        $west = $run("&dimensions[region]={$this->west}");
        $system = $run('&source=system&transaction_type_keys[]=system.accrual');

        expect($all->json('data.rows.0.values'))->toBe(['month' => '1500.00', 'ytd' => '1500.00'])
            ->and($west->json('data.rows.0.values'))->toBe(['month' => '300.00', 'ytd' => '300.00'])
            ->and($west->json('data.parameters.dimensions'))->toBe(['region' => $this->west])
            ->and($system->json('data.rows.0.values.month'))->toBe('800.00');
    });
});
