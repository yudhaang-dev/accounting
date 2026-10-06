<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Reporting\Domain\ReportDefinition;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function rtAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function rtPost(string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [new PostJournalLine(rtAccountId($debit), $amount, '0'), new PostJournalLine(rtAccountId($credit), '0', $amount)],
    ));
}

function rtImport(array $keys, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/report-definitions/import-template', ['template' => 'general-id', 'confirmed' => true, 'keys' => $keys]);
}

function rtResult(string $key, string $query): array
{
    $id = collect(test()->actingAs(test()->viewer)->getJson(test()->url.'/report-definitions')->json('data'))->firstWhere('key', $key)['id'];

    return test()->actingAs(test()->viewer)->getJson(test()->url."/report-definitions/{$id}/result?{$query}")->assertOk()->json('data');
}

/** @return array<string, string|null> row key => value of the first column */
function rtValues(array $data, string $column): array
{
    return collect($data['rows'])->mapWithKeys(fn (array $row): array => [$row['key'] => $row['values'][$column]])->all();
}

function rtDefinitionKeys(): array
{
    return ReportDefinition::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->orderBy('key')->pluck('key')->all();
}

describe('report definition templates (RP-FR-01, X-AC-03)', function () {
    it('lists the templates and shows the definitions of one for review without creating anything', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/report-definition-templates")
            ->assertOk()->assertJsonPath('data.0.key', 'general-id')->assertJsonPath('data.0.definition_count', 3);

        $this->actingAs($this->admin)->getJson("{$this->url}/report-definition-templates/general-id")
            ->assertOk()
            ->assertJsonPath('data.definitions.0.key', 'income_statement')
            ->assertJsonPath('data.definitions.1.kind', 'balance_sheet')
            ->assertJsonPath('data.definitions.0.structure.rows.0.accounts.0.code', '4110');

        $this->actingAs($this->admin)->getJson("{$this->url}/report-definition-templates/unknown")->assertNotFound();
        expect(rtDefinitionKeys())->toBe([]);
    });

    it('creates nothing until the template is confirmed', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions/import-template", ['template' => 'general-id', 'keys' => ['balance_sheet']])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions/import-template", ['template' => 'general-id', 'confirmed' => false, 'keys' => ['balance_sheet']])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions/import-template", ['template' => 'general-id', 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('keys');

        expect(rtDefinitionKeys())->toBe([]);
    });

    it('creates only the picked definitions as version 1 on the accounts of the company', function () {
        rtImport(['balance_sheet', 'income_statement'])->assertCreated()->assertJsonPath('data.created', ['balance_sheet', 'income_statement']);

        expect(rtDefinitionKeys())->toBe(['balance_sheet', 'income_statement']);

        $list = collect($this->actingAs($this->admin)->getJson("{$this->url}/report-definitions")->json('data'))->keyBy('key');
        $shown = $this->actingAs($this->admin)->getJson("{$this->url}/report-definitions/{$list['balance_sheet']['id']}")->assertOk()->json('data');

        expect($list['balance_sheet'])->toMatchArray(['name' => 'Neraca', 'kind' => 'balance_sheet'])
            ->and($shown['structure']['rows'][0]['accounts'])->toEqual([['type' => 'parent', 'id' => rtAccountId('1100')]])
            ->and($shown['version'])->toBe(1);
    });

    it('refuses an unknown template or definition, and is all-or-nothing when a key is already used', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions/import-template", ['template' => 'nope', 'confirmed' => true, 'keys' => ['balance_sheet']])
            ->assertUnprocessable()->assertJsonValidationErrors('template');
        rtImport(['balance_sheet', 'unknown_report'])->assertUnprocessable()->assertJsonValidationErrors('keys.1');

        $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [
            'key' => 'income_statement', 'name' => 'Mine',
            'structure' => ['rows' => [['key' => 'all', 'label' => 'All', 'type' => 'accounts', 'accounts' => [['type' => 'class', 'class' => 'income']]]], 'columns' => [['key' => 'p', 'label' => 'P', 'kind' => 'period']]],
        ])->assertCreated();

        rtImport(['balance_sheet', 'income_statement'])->assertUnprocessable()->assertJsonValidationErrors('keys');

        expect(rtDefinitionKeys())->toBe(['income_statement']);
    });

    it('names the account code that the chart of the company lacks', function () {
        Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '7300')->update(['code' => '7399']);

        rtImport(['income_statement'])->assertUnprocessable()->assertJsonValidationErrors('keys.0');
        rtImport(['balance_sheet'])->assertCreated();

        expect(rtDefinitionKeys())->toBe(['balance_sheet']);
    });

    it('is managed with report-definitions.manage only', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/report-definition-templates")->assertForbidden();
        $this->actingAs($this->viewer)->getJson("{$this->url}/report-definition-templates/general-id")->assertForbidden();
        rtImport(['balance_sheet'], $this->viewer)->assertForbidden();

        expect(rtDefinitionKeys())->toBe([]);
    });

    it('records the import in the audit log', function () {
        rtImport(['balance_sheet'])->assertCreated();

        expect(DB::table('pf_audit_log')->where('event', 'report_definitions.template_imported')->count())->toBe(1);
    });
});

describe('the suggested reports on the template chart of accounts', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/reporting/account-role-mappings/cash_equivalents", ['lock_version' => 0, 'account_ids' => [rtAccountId('1111'), rtAccountId('1112')]])->assertOk();

        rtPost('2026-03-10', '1112', '4110', '1000000.00');
        rtPost('2026-03-15', '5110', '1111', '400000.00');
        rtPost('2026-03-20', '1112', '3110', '5000000.00');
        rtPost('2026-03-25', '1131', '2110', '250000.00');

        rtImport(['income_statement', 'balance_sheet', 'cash_flow_indirect'])->assertCreated();
    });

    it('produces an income statement whose subtotals follow the template layout', function () {
        $values = rtValues(rtResult('income_statement', 'from=2026-03-01&to=2026-03-31'), 'period');

        expect($values['net_revenue'])->toBe('1000000.00')
            ->and($values['gross_profit'])->toBe('600000.00')
            ->and($values['net_profit'])->toBe('600000.00')
            ->and($values)->not->toHaveKey('unmapped');
    });

    it('produces a balance sheet that balances, with every account in a row', function () {
        $data = rtResult('balance_sheet', 'from=2026-03-31&to=2026-03-31');
        $values = rtValues($data, 'at');

        expect($values['total_assets'])->toBe('5850000.00')
            ->and($values['total_liabilities_and_equity'])->toBe('5850000.00')
            ->and($values['current_year_earnings'])->toBe('600000.00')
            ->and($data['balance_checks']['at']['balanced'])->toBeTrue()
            ->and($values)->not->toHaveKey('unmapped');
    });

    it('produces an indirect cash flow that agrees with the change in cash', function () {
        $data = rtResult('cash_flow_indirect', 'from=2026-03-01&to=2026-03-31');
        $values = rtValues($data, 'period');

        expect($values['operating_cash'])->toBe('600000.00')
            ->and($values['financing_cash'])->toBe('5000000.00')
            ->and($values['net_increase'])->toBe('5600000.00')
            ->and($values['cash_closing'])->toBe('5600000.00')
            ->and($data['cash_checks']['period']['reconciled'])->toBeTrue()
            ->and($values)->not->toHaveKey('unmapped');
    });
});
