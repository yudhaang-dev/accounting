<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);
});

/** The opening balances of 1 April, with the cutover on 1 June: April and May are history. */
function historyOpening(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/opening-balance-imports', array_merge([
        'source_key' => 'legacy-opening',
        'cutover_date' => '2026-06-01',
        'ledger_start_date' => '2026-04-01',
        'account_mapping' => [
            ['source_code' => 'A-100', 'account_code' => '1111'],
            ['source_code' => 'A-110', 'account_code' => '1112'],
            ['source_code' => 'E-300', 'account_code' => '3110'],
            ['source_code' => 'I-400', 'account_code' => '4110'],
            ['source_code' => 'X-500', 'account_code' => '5110'],
        ],
        'lines' => [
            ['source_account_code' => 'A-100', 'debit' => '7000000.00', 'credit' => '0'],
            ['source_account_code' => 'A-110', 'debit' => '3000000.00', 'credit' => '0'],
            ['source_account_code' => 'X-500', 'debit' => '2000000.00', 'credit' => '0'],
            ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '10000000.00'],
            ['source_account_code' => 'I-400', 'debit' => '0', 'credit' => '2000000.00'],
        ],
    ], $overrides));
}

/** April: 500000 sold into the bank. May: 200000 of cost paid from cash. */
function historyPayload(array $overrides = []): array
{
    return array_merge([
        'source_key' => 'legacy-history',
        'granularity' => 'period_summary',
        'journals' => [
            ['accounting_date' => '2026-04-30', 'description' => 'April', 'lines' => [
                ['source_account_code' => 'A-110', 'debit' => '500000.00', 'credit' => '0'],
                ['source_account_code' => 'I-400', 'debit' => '0', 'credit' => '500000.00'],
            ]],
            ['accounting_date' => '2026-05-31', 'description' => 'May', 'lines' => [
                ['source_account_code' => 'X-500', 'debit' => '200000.00', 'credit' => '0'],
                ['source_account_code' => 'A-100', 'debit' => '0', 'credit' => '200000.00'],
            ]],
        ],
        'cutover_balances' => [
            ['source_account_code' => 'A-100', 'debit' => '6800000.00', 'credit' => '0'],
            ['source_account_code' => 'A-110', 'debit' => '3500000.00', 'credit' => '0'],
            ['source_account_code' => 'X-500', 'debit' => '2200000.00', 'credit' => '0'],
            ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '10000000.00'],
            ['source_account_code' => 'I-400', 'debit' => '0', 'credit' => '2500000.00'],
        ],
    ], $overrides);
}

function importHistory(array $overrides = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/history-imports', historyPayload($overrides));
}

describe('history import (GL-FR-11)', function () {
    it('posts a journal per period and records the granularity on the opening balance batch', function () {
        historyOpening()->assertCreated();

        $response = importHistory()->assertCreated()
            ->assertJsonPath('data.granularity', 'period_summary')
            ->assertJsonPath('data.journal_count', 2);

        $journals = DB::table('gl_journals')->where('transaction_type_key', 'system.history_import')->orderBy('accounting_date')->get();

        expect($journals->pluck('accounting_date')->all())->toBe(['2026-04-30', '2026-05-31'])
            ->and($journals->pluck('source')->unique()->all())->toBe(['system'])
            ->and(DB::table('gl_opening_balance_batches')->value('history_granularity'))->toBe('period_summary');

        $this->actingAs($this->admin)->getJson("{$this->url}/history-imports")->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->actingAs($this->admin)->getJson("{$this->url}/opening-balance-imports")->assertOk()->assertJsonPath('data.history_granularity', 'period_summary');

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'history.imported')->sole();
        expect($audit->properties['journals'])->toBe(2);
    });

    it('accepts several journals of a period as transactions but only one as a period summary', function () {
        historyOpening()->assertCreated();

        $twice = historyPayload()['journals'];
        $twice[] = $twice[0];
        $twice[2]['accounting_date'] = '2026-04-15';

        importHistory(['journals' => $twice])->assertUnprocessable()->assertJsonValidationErrors(['journals.2.accounting_date']);
        expect(DB::table('gl_history_imports')->count())->toBe(0);

        // The same journals as transactions add 500000 more to the bank and income, so the source says so too.
        $balances = historyPayload()['cutover_balances'];
        $balances[1]['debit'] = '4000000.00';
        $balances[4]['credit'] = '3000000.00';

        importHistory(['granularity' => 'transactions', 'journals' => $twice, 'cutover_balances' => $balances])->assertCreated()
            ->assertJsonPath('data.journal_count', 3);
    });

    it('refuses history that does not add up to the source trial balance at the cutover date, and posts nothing', function () {
        historyOpening()->assertCreated();

        $balances = historyPayload()['cutover_balances'];
        $balances[1]['debit'] = '3400000.00';

        $response = importHistory(['cutover_balances' => $balances])->assertUnprocessable();

        expect($response->json('errors.cutover_balances.0'))->toContain('1112')->toContain('3500000.00')->toContain('3400000.00')
            ->and(DB::table('gl_journals')->where('transaction_type_key', 'system.history_import')->count())->toBe(0)
            ->and(DB::table('gl_history_imports')->count())->toBe(0)
            ->and(DB::table('gl_opening_balance_batches')->value('history_granularity'))->toBeNull();
    });

    it('refuses a ledger account that the source trial balance does not report', function () {
        historyOpening()->assertCreated();

        $balances = array_slice(historyPayload()['cutover_balances'], 0, 4);

        importHistory(['cutover_balances' => $balances])->assertUnprocessable()->assertJsonValidationErrors(['cutover_balances']);
    });

    it('keeps the history inside the span between the ledger start and the day before the cutover', function () {
        historyOpening()->assertCreated();

        foreach (['2026-03-31', '2026-06-01'] as $date) {
            $journals = historyPayload()['journals'];
            $journals[0]['accounting_date'] = $date;

            importHistory(['journals' => $journals])->assertUnprocessable()->assertJsonValidationErrors(['journals.0.accounting_date']);
        }
    });

    it('needs an opening balance batch that leaves history to import', function () {
        importHistory()->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        historyOpening(['cutover_date' => '2026-04-01'])->assertCreated();

        importHistory()->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('returns the import when the same source key and content come again and refuses the rest', function () {
        historyOpening()->assertCreated();
        $first = importHistory()->assertCreated();

        importHistory()->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        expect(DB::table('gl_journals')->where('transaction_type_key', 'system.history_import')->count())->toBe(2);

        $journals = historyPayload()['journals'];
        $journals[0]['description'] = 'Changed';

        importHistory(['journals' => $journals])->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        importHistory(['source_key' => 'another'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('uses the mapping of the opening balance batch and accepts additions, and refuses a code with none', function () {
        historyOpening()->assertCreated();

        $journals = historyPayload()['journals'];
        $journals[1]['lines'][0]['source_account_code'] = 'X-600';

        $failed = importHistory(['journals' => $journals])->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED');

        expect($failed->json('errors'))->toHaveKey('journals.1.lines.0.source_account_code');

        importHistory(['journals' => $journals, 'account_mapping' => [['source_code' => 'X-600', 'account_code' => '5110']]])->assertCreated();
    });

    it('needs the opening-balances.import permission', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        importHistory(as: $viewer)->assertForbidden();
        $this->actingAs($viewer)->getJson("{$this->url}/history-imports")->assertNotFound();
    });
});
