<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceImport;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceLine;
use App\Modules\GeneralLedger\Contracts\OpeningBalances;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);
});

function openingAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

/** A source trial balance that starts mid-year: bank and cash against capital, with year to date income and expense. */
function openingPayload(array $overrides = []): array
{
    return array_merge([
        'source_key' => 'legacy-2026-04',
        'source_system' => 'legacy',
        'cutover_date' => '2026-04-01',
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
    ], $overrides);
}

function importOpening(array $overrides = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/opening-balance-imports', openingPayload($overrides));
}

/** Runs the contract directly, which is how the orchestration hands over open item totals. */
function openingFailure(array $totals, array $lines): ApiException|ValidationException
{
    try {
        app(OpeningBalances::class)->import(new OpeningBalanceImport(
            companyId: test()->companyId,
            sourceKey: 'legacy-2026-04',
            cutoverDate: '2026-04-01',
            ledgerStartDate: '2026-04-01',
            accountMapping: ['A-110' => '1112', 'E-300' => '3110'],
            dimensionMapping: [],
            lines: $lines,
            importedBy: test()->admin->id,
            controlTotals: $totals,
        ));
    } catch (ApiException|ValidationException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the import to fail.');
}

describe('opening balance import (GL-FR-11, GL-AC-07)', function () {
    it('posts the batch as one system journal dated the ledger start date and records it', function () {
        $response = importOpening()->assertCreated()
            ->assertJsonPath('data.source_key', 'legacy-2026-04')
            ->assertJsonPath('data.cutover_date', '2026-04-01')
            ->assertJsonPath('data.ledger_start_date', '2026-04-01')
            ->assertJsonPath('data.line_count', 5)
            ->assertJsonPath('data.history_granularity', null);

        $journal = DB::table('gl_journals')->find($response->json('data.journal_id'));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $journal->id)->orderBy('line_number')->get();

        expect($journal->accounting_date)->toBe('2026-04-01')
            ->and($journal->source)->toBe('system')
            ->and($journal->transaction_type_key)->toBe('system.opening_balance')
            ->and($journal->number)->toBe($response->json('data.journal_number'))
            ->and($lines)->toHaveCount(5)
            ->and((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'))
            ->and([$lines[0]->account_id, (float) $lines[0]->debit])->toBe([openingAccountId('1111'), 7000000.0]);

        $this->actingAs($this->admin)->getJson("{$this->url}/opening-balance-imports")->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'));

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'opening_balance.imported')->sole();
        expect($audit->properties['journal_id'])->toBe($journal->id);
    });

    it('answers 404 before any import', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/opening-balance-imports")->assertNotFound();
    });

    it('refuses a source account code without a mapping and posts nothing', function () {
        $lines = openingPayload()['lines'];
        $lines[2]['source_account_code'] = 'X-999';

        $response = importOpening(['lines' => $lines])->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED');

        expect($response->json('errors')['lines.2.source_account_code'][0])->toContain('X-999')
            ->and(DB::table('gl_journals')->count())->toBe(0)
            ->and(DB::table('gl_opening_balance_batches')->count())->toBe(0);
    });

    it('refuses a mapping to an account the company does not have', function () {
        importOpening(['account_mapping' => [
            ['source_code' => 'A-100', 'account_code' => '9999'],
            ['source_code' => 'A-110', 'account_code' => '1112'],
            ['source_code' => 'E-300', 'account_code' => '3110'],
            ['source_code' => 'I-400', 'account_code' => '4110'],
            ['source_code' => 'X-500', 'account_code' => '5110'],
        ]])->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED');
    });

    it('returns the batch when the same source key and content come again and posts nothing twice', function () {
        $first = importOpening()->assertCreated();

        importOpening()->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.journal_id', $first->json('data.journal_id'));

        expect(DB::table('gl_journals')->count())->toBe(1)
            ->and(DB::table('gl_opening_balance_batches')->count())->toBe(1)
            ->and((float) DB::table('gl_account_balances')->where('account_id', openingAccountId('1111'))->sum('debit'))->toBe(7000000.0);
    });

    it('refuses the same source key with other content and another batch for the company', function () {
        importOpening()->assertCreated();

        $lines = openingPayload()['lines'];
        $lines[0]['debit'] = '7000001.00';
        $lines[3]['credit'] = '10000001.00';

        importOpening(['lines' => $lines])->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        importOpening(['source_key' => 'legacy-again'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        expect(DB::table('gl_journals')->count())->toBe(1);
    });

    it('refuses a batch that does not balance', function () {
        $lines = openingPayload()['lines'];
        $lines[3]['credit'] = '9999999.00';

        importOpening(['lines' => $lines])->assertUnprocessable()->assertJsonPath('code', 'UNBALANCED_ENTRY');

        expect(DB::table('gl_opening_balance_batches')->count())->toBe(0);
    });

    it('refuses an inactive or parent account, with the line that names it', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/".openingAccountId('1111').'/deactivate')->assertOk();

        importOpening()->assertUnprocessable()->assertJsonPath('code', 'ACCOUNT_NOT_POSTABLE')
            ->assertJsonStructure(['errors' => ['lines.0.account_id']]);
    });

    it('refuses repeated balances of the same account, currency and dimensions', function () {
        $lines = openingPayload()['lines'];
        $lines[] = ['source_account_code' => 'A-100', 'debit' => '1.00', 'credit' => '0'];

        importOpening(['lines' => $lines])->assertUnprocessable()->assertJsonValidationErrors(['lines.5.source_account_code']);
    });

    it('needs a ledger start that begins a period, not after the cutover', function () {
        importOpening(['cutover_date' => '2026-04-15', 'ledger_start_date' => '2026-04-10'])->assertUnprocessable()
            ->assertJsonValidationErrors(['ledger_start_date']);
        importOpening(['cutover_date' => '2026-04-01', 'ledger_start_date' => '2026-05-01'])->assertUnprocessable()
            ->assertJsonValidationErrors(['ledger_start_date']);
        importOpening(['cutover_date' => '2027-04-01', 'ledger_start_date' => '2027-04-01'])->assertUnprocessable()
            ->assertJsonPath('code', 'PERIOD_NOT_OPEN');
    });

    it('keeps the history gap between the ledger start and the cutover', function () {
        importOpening(['cutover_date' => '2026-06-01', 'ledger_start_date' => '2026-04-01'])->assertCreated()
            ->assertJsonPath('data.cutover_date', '2026-06-01')
            ->assertJsonPath('data.ledger_start_date', '2026-04-01');
    });

    it('carries year to date income and expense only when the ledger starts inside the fiscal year', function () {
        importOpening(['cutover_date' => '2026-01-01'])->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $lines = array_values(array_filter(openingPayload()['lines'], fn (array $line): bool => ! in_array($line['source_account_code'], ['X-500', 'I-400'], true)));
        $lines[0]['debit'] = '7000000.00';
        $lines[1]['debit'] = '3000000.00';

        importOpening(['cutover_date' => '2026-01-01', 'lines' => $lines])->assertCreated();
    });

    it('translates dimension values through the mapping and refuses codes without one', function () {
        $type = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types", ['key' => 'branch', 'label' => 'Branch'])->assertCreated()->json('data.id');
        $value = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type, 'code' => 'JKT', 'name' => 'Jakarta'])->assertCreated()->json('data.id');

        $lines = openingPayload()['lines'];
        $lines[2]['dimensions'] = ['branch' => 'OLD-JKT'];

        importOpening(['lines' => $lines])->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED')
            ->assertJsonStructure(['errors' => ['lines.2.dimensions.branch']]);

        $response = importOpening(['lines' => $lines, 'dimension_mapping' => [['type' => 'branch', 'source_code' => 'OLD-JKT', 'value_id' => $value]]])->assertCreated();

        $setId = DB::table('gl_journal_lines')->where('journal_id', $response->json('data.journal_id'))->where('line_number', 3)->value('dimension_set_id');

        expect(DB::table('md_dimension_set_items')->where('dimension_set_id', $setId)->value('dimension_value_id'))->toBe($value);
    });

    it('posts to a control account only with the total of its open items, and refuses a difference', function () {
        // The bank account is kept by a subledger: it takes subledger postings only.
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/".openingAccountId('1112'), ['lock_version' => 1, 'allowed_sources' => ['subledger']])->assertOk();

        $lines = [
            new OpeningBalanceLine('A-110', '3000000.00', '0'),
            new OpeningBalanceLine('E-300', '0', '3000000.00'),
        ];
        $bank = openingAccountId('1112');

        // Without totals the control account has nothing to be checked against, so the import is refused.
        importOpening()->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        expect(openingFailure([], $lines)->errors())->toHaveKey('lines');

        $failure = openingFailure([$bank => '2999999.00'], $lines);
        expect($failure)->toBeInstanceOf(ValidationException::class)
            ->and($failure->errors()['lines'][0])->toContain('1112')
            ->and(DB::table('gl_journals')->count())->toBe(0)
            ->and(DB::table('gl_opening_balance_batches')->count())->toBe(0);

        $batch = app(OpeningBalances::class)->import(new OpeningBalanceImport(
            companyId: $this->companyId,
            sourceKey: 'legacy-2026-04',
            cutoverDate: '2026-04-01',
            ledgerStartDate: '2026-04-01',
            accountMapping: ['A-110' => '1112', 'E-300' => '3110'],
            dimensionMapping: [],
            lines: $lines,
            importedBy: $this->admin->id,
            controlTotals: [$bank => '3000000.00'],
        ));

        expect($batch->replayed)->toBeFalse()
            ->and(DB::table('gl_journals')->where('id', $batch->journalId)->value('source'))->toBe('system');
    });

    it('does not open the control account to other system postings', function () {
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/".openingAccountId('1112'), ['lock_version' => 1, 'allowed_sources' => ['subledger']])->assertOk();

        $posting = fn () => app(PostingService::class)->post(new PostJournal(
            companyId: $this->companyId,
            accountingDate: '2026-04-01',
            description: 'Manual',
            source: JournalSource::System,
            lines: [
                new PostJournalLine(openingAccountId('1112'), '10.00', '0'),
                new PostJournalLine(openingAccountId('3110'), '0', '10.00'),
            ],
        ));

        expect($posting)->toThrow(ApiException::class);
    });

    it('needs the opening-balances.import permission', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        importOpening(as: $viewer)->assertForbidden();
        $this->actingAs($viewer)->getJson("{$this->url}/opening-balance-imports")->assertNotFound();
    });
});
