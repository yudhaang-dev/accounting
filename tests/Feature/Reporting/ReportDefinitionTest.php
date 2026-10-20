<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Reporting\Domain\ReportDefinitionVersion;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = rdAccountId($this->companyId, '1112');
    $this->sales = rdAccountId($this->companyId, '4110');
    $this->expense = rdAccountId($this->companyId, '6220');

    rdPost($this->companyId, '2026-01-10', $this->bank, $this->sales, '1000.00');
    rdPost($this->companyId, '2026-02-05', $this->bank, $this->sales, '500.00');
    rdPost($this->companyId, '2026-02-20', $this->expense, $this->bank, '200.00');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function rdAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function rdPost(string $companyId, string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
    ));
}

/** Revenue, expense and profit, for the month and the year to date. */
function rdStructure(): array
{
    return [
        'basis' => 'movement',
        'rows' => [
            ['key' => 'income', 'label' => 'Income', 'type' => 'heading'],
            ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'account', 'id' => test()->sales]]],
            ['key' => 'costs', 'label' => 'Costs', 'type' => 'accounts', 'accounts' => [['type' => 'account', 'id' => test()->expense]]],
            ['key' => 'profit', 'label' => 'Profit', 'type' => 'subtotal', 'sign' => 'debit', 'terms' => [['row' => 'revenue', 'sign' => 1], ['row' => 'costs', 'sign' => -1]]],
        ],
        'columns' => [
            ['key' => 'month', 'label' => 'Month', 'kind' => 'period'],
            ['key' => 'ytd', 'label' => 'YTD', 'kind' => 'ytd'],
        ],
    ];
}

function rdCreate(array $overrides = [])
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/report-definitions', [
        'key' => 'income_statement', 'name' => 'Income statement', 'structure' => rdStructure(), ...$overrides,
    ]);
}

function rdRun(string $id, string $query = 'from=2026-02-01&to=2026-02-28', ?User $as = null)
{
    return test()->actingAs($as ?? test()->viewer)->getJson(test()->url."/report-definitions/{$id}/result?{$query}");
}

function rdRow(array $result, string $key): array
{
    return collect($result['rows'])->firstWhere('key', $key);
}

describe('definitions are versioned data (RP-FR-01)', function () {
    it('creates version 1, shows it and refuses a repeated key', function () {
        $created = rdCreate()->assertCreated()->json('data');

        expect($created)->toMatchArray(['key' => 'income_statement', 'current_version' => 1, 'version' => 1])
            ->and($created['structure']['rows'])->toHaveCount(4)
            ->and(AuditEntry::where('event', 'report_definition.created')->count())->toBe(1);

        $this->actingAs($this->viewer)->getJson("{$this->url}/report-definitions/{$created['id']}")->assertOk()->assertJsonPath('data.structure.columns.1.kind', 'ytd');
        $this->actingAs($this->viewer)->getJson("{$this->url}/report-definitions")->assertOk()->assertJsonCount(1, 'data');

        rdCreate()->assertUnprocessable()->assertJsonValidationErrors(['key']);
    });

    it('asks for report-definitions.manage to create or change a definition', function () {
        $this->actingAs($this->viewer)->postJson("{$this->url}/report-definitions", ['key' => 'x', 'name' => 'X', 'structure' => rdStructure()])->assertForbidden();

        $id = rdCreate()->json('data.id');
        $this->actingAs($this->viewer)->putJson("{$this->url}/report-definitions/{$id}", ['name' => 'Y', 'lock_version' => 1])->assertForbidden();
    });

    it('adds a version when the structure changes and keeps the earlier one readable (RP-AC-07)', function () {
        $created = rdCreate()->json('data');
        $structure = rdStructure();
        $structure['rows'][] = ['key' => 'cash', 'label' => 'Cash', 'type' => 'accounts', 'accounts' => [['type' => 'account', 'id' => $this->bank]]];

        $updated = $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$created['id']}", ['structure' => $structure, 'lock_version' => $created['lock_version']])
            ->assertOk()->json('data');

        expect($updated)->toMatchArray(['current_version' => 2, 'version' => 2, 'lock_version' => $created['lock_version'] + 1])
            ->and($updated['structure']['rows'])->toHaveCount(5);

        $this->actingAs($this->viewer)->getJson("{$this->url}/report-definitions/{$created['id']}?version=1")->assertOk()->assertJsonCount(4, 'data.structure.rows');

        // The same structure again is not a new version; a rename alone is not either.
        $same = $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$created['id']}", ['name' => 'Renamed', 'structure' => $structure, 'lock_version' => $updated['lock_version']])
            ->assertOk()->json('data');

        expect($same)->toMatchArray(['name' => 'Renamed', 'current_version' => 2]);

        // A version never changes, not even in the database.
        $version = ReportDefinitionVersion::withoutGlobalScope(CompanyScope::class)->where('definition_id', $created['id'])->firstOrFail();
        expect(fn () => DB::transaction(fn () => $version->update(['structure' => []])))->toThrow(QueryException::class);
    });

    it('conflicts on a stale lock version', function () {
        $created = rdCreate()->json('data');

        $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$created['id']}", ['name' => 'Z', 'lock_version' => 9])
            ->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('refuses structures that would make the report wrong', function () {
        $structure = rdStructure();
        $structure['rows'][2]['accounts'] = [['type' => 'account', 'id' => $this->sales]];
        rdCreate(['structure' => $structure])->assertUnprocessable()->assertJsonValidationErrors(['structure.rows.2.accounts']);

        $structure = rdStructure();
        $structure['rows'][1]['accounts'] = [['type' => 'account', 'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']];
        rdCreate(['structure' => $structure])->assertUnprocessable()->assertJsonValidationErrors(['structure.rows.1.accounts.0.id']);

        $structure = rdStructure();
        $structure['rows'][3]['terms'] = [['row' => 'later', 'sign' => 1]];
        rdCreate(['structure' => $structure])->assertUnprocessable()->assertJsonValidationErrors(['structure.rows.3.terms.0.row']);

        $structure = rdStructure();
        $structure['rows'][0]['key'] = 'unmapped';
        $structure['columns'][] = ['key' => 'gap', 'label' => 'Gap', 'kind' => 'difference', 'minuend' => 'month', 'subtrahend' => 'later'];
        rdCreate(['structure' => $structure])->assertUnprocessable()->assertJsonValidationErrors(['structure.rows.0.key', 'structure.columns.2.subtrahend']);
    });
});

describe('evaluation (RP-FR-01, RP-FR-02)', function () {
    it('computes rows by account set and subtotals with signs, per column', function () {
        $id = rdCreate()->json('data.id');

        $result = rdRun($id)->assertOk()->json('data');

        expect(rdRow($result, 'revenue')['values'])->toBe(['month' => '500.00', 'ytd' => '1500.00'])
            ->and(rdRow($result, 'costs')['values'])->toBe(['month' => '200.00', 'ytd' => '200.00'])
            ->and(rdRow($result, 'profit')['values'])->toBe(['month' => '300.00', 'ytd' => '1300.00'])
            ->and(rdRow($result, 'income')['values'])->toBe(['month' => null, 'ytd' => null])
            ->and($result['columns'][1])->toMatchArray(['from' => '2026-01-01', 'to' => '2026-02-28', 'available' => true])
            ->and($result['functional_currency'])->toBe('IDR')
            ->and($result['restricted'])->toBeFalse();
    });

    it('selects accounts by class and by parent with everything below it, never by code range', function () {
        $parent = Account::withoutGlobalScope(CompanyScope::class)->where('id', $this->sales)->value('parent_id');

        $structure = rdStructure();
        $structure['rows'] = [
            ['key' => 'all_income', 'label' => 'Income', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'income']]],
            ['key' => 'all_expense', 'label' => 'Expense', 'type' => 'accounts', 'accounts' => [['type' => 'class', 'class' => 'expense']]],
        ];
        $id = rdCreate(['structure' => $structure])->assertCreated()->json('data.id');

        $result = rdRun($id)->json('data');
        expect(rdRow($result, 'all_income')['values']['month'])->toBe('500.00')
            ->and(rdRow($result, 'all_expense')['values']['month'])->toBe('200.00');

        if ($parent !== null) {
            $structure['rows'] = [['key' => 'group', 'label' => 'Group', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'parent', 'id' => $parent]]]];
            $groupId = rdCreate(['key' => 'group_report', 'structure' => $structure])->assertCreated()->json('data.id');

            expect(rdRow(rdRun($groupId)->json('data'), 'group')['values']['month'])->toBe('500.00');
        }
    });

    it('shows accounts that no row claims in the unmapped row and keeps the totals reconciling (RP-AC-07)', function () {
        $id = rdCreate()->json('data.id');

        $result = rdRun($id)->json('data');

        // Bank: +500 -200 in February, debit positive; it appears in no row of the definition.
        expect(rdRow($result, 'unmapped'))->toMatchArray(['label' => 'Not mapped to a row', 'type' => 'unmapped'])
            ->and(rdRow($result, 'unmapped')['values'])->toBe(['month' => '300.00', 'ytd' => '1300.00'])
            ->and(collect($result['unmapped_accounts'])->pluck('code')->all())->toBe(['1112']);

        // Subtotals may add the unmapped row, so the sum of the whole ledger is one definition away.
        $structure = rdStructure();
        $structure['rows'][] = ['key' => 'everything', 'label' => 'Everything', 'type' => 'subtotal', 'terms' => [['row' => 'revenue', 'sign' => -1], ['row' => 'costs', 'sign' => 1], ['row' => 'unmapped', 'sign' => 1]]];
        $everything = rdCreate(['key' => 'everything', 'structure' => $structure])->assertCreated()->json('data.id');

        expect(rdRow(rdRun($everything)->json('data'), 'everything')['values']['month'])->toBe('0.00');
    });

    it('leaves the unmapped row out when every posted account is claimed', function () {
        $structure = rdStructure();
        $structure['rows'][] = ['key' => 'cash', 'label' => 'Cash', 'type' => 'accounts', 'accounts' => [['type' => 'account', 'id' => $this->bank]]];
        $id = rdCreate(['structure' => $structure])->json('data.id');

        $result = rdRun($id)->json('data');

        expect(collect($result['rows'])->pluck('key')->all())->not->toContain('unmapped')
            ->and($result['unmapped_accounts'])->toBe([]);
    });

    it('reads balance, opening and closing bases and as-of columns', function () {
        $structure = rdStructure();
        $structure['rows'] = [
            ['key' => 'bank_balance', 'label' => 'Bank', 'type' => 'accounts', 'basis' => 'balance', 'accounts' => [['type' => 'account', 'id' => $this->bank]]],
            ['key' => 'bank_opening', 'label' => 'Opening', 'type' => 'accounts', 'basis' => 'opening', 'accounts' => [['type' => 'account', 'id' => $this->expense]]],
        ];
        $structure['columns'] = [['key' => 'on', 'label' => 'On', 'kind' => 'as_of'], ['key' => 'month', 'label' => 'Month', 'kind' => 'period']];
        $id = rdCreate(['structure' => $structure])->json('data.id');

        $result = rdRun($id)->json('data');

        expect(rdRow($result, 'bank_balance')['values'])->toBe(['on' => '1300.00', 'month' => '1300.00'])
            ->and(rdRow($result, 'bank_opening')['values'])->toBe(['on' => '200.00', 'month' => '0.00']);
    });

    it('shifts columns back, and a column outside the fiscal calendar has no value instead of zero', function () {
        $structure = rdStructure();
        $structure['columns'] = [
            ['key' => 'month', 'label' => 'Month', 'kind' => 'period'],
            ['key' => 'prior', 'label' => 'Prior month', 'kind' => 'period', 'shift' => ['years' => 0, 'months' => -1]],
            ['key' => 'ytd_prior_year', 'label' => 'YTD last year', 'kind' => 'ytd', 'shift' => ['years' => -1, 'months' => 0]],
            ['key' => 'change', 'label' => 'Change', 'kind' => 'difference', 'minuend' => 'month', 'subtrahend' => 'prior'],
            ['key' => 'no_change', 'label' => 'No change', 'kind' => 'difference', 'minuend' => 'month', 'subtrahend' => 'ytd_prior_year'],
        ];
        $id = rdCreate(['structure' => $structure])->json('data.id');

        $result = rdRun($id)->json('data');

        expect($result['columns'][1])->toMatchArray(['from' => '2026-01-01', 'to' => '2026-01-31', 'available' => true])
            ->and($result['columns'][2])->toMatchArray(['from' => null, 'to' => null, 'available' => false])
            ->and($result['columns'][4]['available'])->toBeFalse()
            ->and(rdRow($result, 'revenue')['values'])->toBe(['month' => '500.00', 'prior' => '1000.00', 'ytd_prior_year' => null, 'change' => '-500.00', 'no_change' => null])
            ->and(rdRow($result, 'profit')['values']['change'])->toBe('-700.00');
    });

    it('accepts as_of and runs a pinned version', function () {
        $created = rdCreate()->json('data');
        $changed = rdStructure();
        $changed['rows'][1]['label'] = 'Sales';
        $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$created['id']}", ['structure' => $changed, 'lock_version' => 1])->assertOk();

        $current = rdRun($created['id'], 'as_of=2026-02-28')->assertOk()->json('data');
        expect($current['definition'])->toBe(['id' => $created['id'], 'key' => 'income_statement', 'version' => 2])
            ->and(rdRow($current, 'revenue')['label'])->toBe('Sales');

        $first = rdRun($created['id'], 'as_of=2026-02-28&version=1')->json('data');
        expect($first['definition']['version'])->toBe(1)
            ->and(rdRow($first, 'revenue')['label'])->toBe('Revenue');

        rdRun($created['id'], 'from=2026-02-01')->assertUnprocessable();
    });
});

describe('CSV export and account drill-down (X-FR-02, API-AC-07)', function () {
    it('lists the accounts each row claims', function () {
        $id = rdCreate()->json('data.id');

        $rows = collect(rdRun($id)->assertOk()->json('data.rows'))->keyBy('key');

        expect($rows['revenue']['accounts'])->toBe([['account_id' => $this->sales, 'code' => '4110', 'name' => $rows['revenue']['accounts'][0]['name']]])
            ->and($rows['costs']['accounts'][0]['account_id'])->toBe($this->expense)
            ->and($rows['income'])->not->toHaveKey('accounts')
            ->and($rows['profit'])->not->toHaveKey('accounts');
    });

    it('downloads the rows with one column per report column', function () {
        $id = rdCreate()->json('data.id');

        $response = $this->actingAs($this->viewer)->get("{$this->url}/report-definitions/{$id}/result?from=2026-02-01&to=2026-02-28", ['Accept' => 'text/csv'])
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $lines = preg_split('/\R/', trim(substr($response->streamedContent(), 3)));

        expect($lines[0])->toStartWith('row,label,type,')
            ->and($lines)->toHaveCount(count(rdStructure()['rows']) + 1 + 1)
            ->and($response->streamedContent())->toContain('revenue,Revenue,accounts,');
    });
});
