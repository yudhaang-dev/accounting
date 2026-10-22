<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\Run;
use App\Modules\Consolidation\Domain\RunBalance;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

// Two companies keeping their books in rupiah, in a group that presents in US dollars: every figure is translated.
beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    [$this->childId] = createWorkspaceAs($this->admin, 'Anak');

    foreach ([$this->parentId, $this->childId] as $companyId) {
        confirmChartOfAccounts($this->admin, $companyId);
        setUpFiscalCalendar($this->admin, $companyId);
    }

    $this->groupId = test()->actingAs($this->admin)->postJson('/api/v1/groups', [
        'name' => 'Grup USD', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'USD', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    $approver = User::factory()->create();
    app(GroupGrants::class)->grant($approver->id, $this->groupId, GroupAbility::Approve);
    $memberId = test()->actingAs($this->admin)->postJson(trGroup('perimeter'), [
        'company_id' => $this->childId, 'control_basis' => 'Majority of votes.', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');
    test()->actingAs($approver)->postJson(trGroup("perimeter/{$memberId}/approve"))->assertOk();

    $this->cash = trxGroupAccount('1000', 'Cash', 'asset');
    $this->revenue = trxGroupAccount('4000', 'Revenue', 'income');
    $this->capital = trxGroupAccount('3100', 'Capital', 'equity');
    $this->cta = trxGroupAccount('3900', 'Translation difference', 'equity');

    $this->closing = trxRateType('closing');
    $this->average = trxRateType('average');
    $this->historical = trxRateType('historical');

    // Parent: a sale of 16 million. Child: a sale of 3.2 million.
    trxPost($this->parentId, '2026-01-10', '1112', '4110', '16000000.00');
    trxPost($this->childId, '2026-01-12', '1111', '4110', '3200000.00');

    trxMap();
});

function trGroup(string $path): string
{
    return '/api/v1/groups/'.test()->groupId.'/'.$path;
}

function trxGroupAccount(string $code, string $name, string $class): string
{
    return test()->actingAs(test()->admin)->postJson(trGroup('accounts'), ['code' => $code, 'name' => $name, 'class' => $class])->assertCreated()->json('data.id');
}

function trxRateType(string $code): string
{
    return test()->actingAs(test()->admin)->postJson(trGroup('rate-types'), ['code' => $code, 'name' => ucfirst($code)])->assertCreated()->json('data.id');
}

function trxAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function trxPost(string $companyId, string $date, string $debitCode, string $creditCode, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [new PostJournalLine(trxAccountId($companyId, $debitCode), $amount, '0'), new PostJournalLine(trxAccountId($companyId, $creditCode), '0', $amount)],
    ));
}

function trxMap(): void
{
    $row = fn (string $company, string $code, string $group) => ['company_id' => $company, 'account_id' => trxAccountId($company, $code), 'group_account_id' => $group];

    test()->actingAs(test()->admin)->putJson(trGroup('account-mappings'), ['lock_version' => 0, 'effective_date' => '2026-01-01', 'mappings' => [
        $row(test()->parentId, '1112', test()->cash), $row(test()->parentId, '4110', test()->revenue), $row(test()->parentId, '3110', test()->capital),
        $row(test()->childId, '1111', test()->cash), $row(test()->childId, '4110', test()->revenue),
    ]])->assertOk();
}

function trxPolicy(): void
{
    test()->actingAs(test()->admin)->putJson(trGroup('translation-policy'), [
        'lock_version' => 0, 'effective_date' => '2026-01-01',
        'closing_rate_type_id' => test()->closing, 'average_rate_type_id' => test()->average,
        'historical_rate_type_id' => test()->historical, 'difference_account_id' => test()->cta,
    ])->assertOk();
}

function trxRate(string $type, string $date, string $rate): void
{
    test()->actingAs(test()->admin)->postJson(trGroup('rates'), ['rate_type_id' => $type, 'currency' => 'IDR', 'effective_date' => $date, 'rate' => $rate])->assertSuccessful();
}

/** Closing rate 1 USD = 16,000 IDR, average rate 1 USD = 16,667 IDR... kept round: 0.0000625 and 0.00006. */
function trxJanuaryRates(): void
{
    trxRate(test()->closing, '2026-01-31', '0.0000625');
    trxRate(test()->average, '2026-01-31', '0.00006');
}

function trxRun(string $from = '2026-01-01', string $to = '2026-01-31')
{
    return test()->actingAs(test()->admin)->postJson(trGroup('runs'), ['from' => $from, 'to' => $to]);
}

function trxLine(array $result, string $code): array
{
    return collect($result['lines'])->firstWhere('code', $code);
}

describe('translation of an entity in another currency (CO-FR-06, CO-AC-03)', function () {
    it('translates assets at the closing rate and income at the average rate, and puts the difference on the equity account of the policy', function () {
        trxPolicy();
        trxJanuaryRates();

        $result = trxRun()->assertCreated()->json('data');

        // Parent 16,000,000 × 0.0000625 = 1000 and × 0.00006 = 960; child 3,200,000 gives 200 and 192.
        expect(trxLine($result, '1000')['closing'])->toBe('1200.00')
            ->and(trxLine($result, '4000')['closing'])->toBe('-1152.00')
            ->and(trxLine($result, '3900')['closing'])->toBe('-48.00')
            ->and(trxLine($result, '3900')['credit'])->toBe('48.00')
            ->and($result['label'])->toBe('management_rollup')
            ->and($result['formal'])->toBeFalse()
            ->and($result['translation_policy_version'])->toBe(1)
            ->and($result['balanced'])->toBeTrue()
            ->and($result['totals']['all']['closing'])->toBe('0.00')
            ->and($result['exceptions'])->toBe([]);

        $difference = trxLine($result, '3900')['sources'];

        expect($difference)->toHaveCount(2)
            ->and(array_column($difference, 'kind'))->toBe(['translation_difference', 'translation_difference'])
            ->and(collect($difference)->firstWhere('company_id', $this->parentId)['closing_credit'])->toBe('40.00');
    });

    it('keeps the amounts in the functional currency and the rates behind every translated figure', function () {
        trxPolicy();
        trxJanuaryRates();

        $result = trxRun()->assertCreated()->json('data');
        $source = collect(trxLine($result, '1000')['sources'])->firstWhere('company_id', $this->parentId);
        $entity = collect($result['entities'])->firstWhere('company_id', $this->parentId);

        expect($source['kind'])->toBe('account')
            ->and((float) $source['translation']['functional']['closing_debit'])->toBe(16000000.0)
            ->and($source['translation']['rates']['closing'])->toStartWith('0.0000625')
            ->and($entity['translation']['policy_version'])->toBe(1)
            ->and($entity['translation']['functional_currency'])->toBe('IDR')
            ->and($entity['translation']['presentation_currency'])->toBe('USD')
            ->and(array_column($entity['translation']['rates'], 'purpose'))->toBe(['closing', 'average'])
            ->and($entity['translation']['rates'][0]['effective_date'])->toBe('2026-01-31');
    });

    it('translates equity at the historical rate of the day the entity entered the perimeter', function () {
        trxPolicy();
        trxJanuaryRates();
        trxRate($this->historical, '2025-12-31', '0.000065');
        trxPost($this->parentId, '2026-01-05', '1112', '3110', '8000000.00');

        $result = trxRun()->assertCreated()->json('data');

        // Parent: cash 24,000,000 → 1500, revenue 960 and capital 8,000,000 × 0.000065 = 520 leave 20 more debit than credit, so 20 goes to credit; the child's 8 makes 28.
        expect(trxLine($result, '3100')['closing'])->toBe('-520.00')
            ->and(trxLine($result, '1000')['closing'])->toBe('1700.00')
            ->and(trxLine($result, '3900')['closing'])->toBe('-28.00')
            ->and(trxLine($result, '3900')['credit'])->toBe('28.00')
            ->and($result['balanced'])->toBeTrue();
    });

    it('translates an opening balance at the rate of the day before the range', function () {
        trxPolicy();
        trxJanuaryRates();
        trxRate($this->closing, '2026-02-28', '0.00006');
        trxRate($this->average, '2026-02-28', '0.000058');

        $result = trxRun('2026-02-01', '2026-02-28')->assertCreated()->json('data');
        $cash = collect(trxLine($result, '1000')['sources'])->firstWhere('company_id', $this->parentId);

        // 16,000,000 stood at 1000 on 31 January and stands at 960 on 28 February.
        expect($cash['opening_debit'])->toBe('1000.00')
            ->and($cash['closing_debit'])->toBe('960.00')
            ->and(trxLine($result, '3900')['opening_credit'])->toBe('48.00')
            ->and($result['balanced'])->toBeTrue();
    });

    it('asks only for the rates a balance really uses', function () {
        trxPolicy();
        trxJanuaryRates();

        // No historical rate was recorded and none is needed: the entities hold no equity.
        trxRun()->assertCreated();
    });
});

describe('what a translated run needs (CO-AC-03)', function () {
    it('is refused without a translation policy, naming each entity', function () {
        trxJanuaryRates();

        trxRun()->assertUnprocessable()->assertJsonValidationErrors(["entities.{$this->parentId}", "entities.{$this->childId}"]);

        expect(Run::where('group_id', $this->groupId)->count())->toBe(0);
    });

    it('is refused when a rate is missing, and says which', function () {
        trxPolicy();
        trxRate($this->closing, '2026-01-31', '0.0000625');

        $response = trxRun()->assertUnprocessable()->assertJsonValidationErrors(["entities.{$this->parentId}"]);

        expect($response->json('errors')["entities.{$this->parentId}"][0])->toContain('average')->toContain('IDR')->toContain('2026-01-31')
            ->and(Run::where('group_id', $this->groupId)->count())->toBe(0);
    });

    it('does not fall back to a rate of another date: the rate must be on or before the date asked', function () {
        trxPolicy();
        trxRate($this->closing, '2026-02-15', '0.0000625');
        trxRate($this->average, '2026-02-15', '0.00006');

        trxRun()->assertUnprocessable()->assertJsonValidationErrors(["entities.{$this->parentId}"]);
    });

    it('uses the version of the policy in force on the cutoff date', function () {
        trxPolicy();
        trxJanuaryRates();
        $other = trxGroupAccount('3910', 'Other translation difference', 'equity');
        $this->actingAs($this->admin)->putJson(trGroup('translation-policy'), [
            'lock_version' => 1, 'effective_date' => '2026-03-01',
            'closing_rate_type_id' => $this->closing, 'average_rate_type_id' => $this->average,
            'historical_rate_type_id' => $this->historical, 'difference_account_id' => $other,
        ])->assertOk();

        $result = trxRun()->assertCreated()->json('data');

        expect($result['translation_policy_version'])->toBe(1)->and(trxLine($result, '3900'))->not->toBeNull();
    });
});

describe('a translated run is kept as it was (CO-FR-09, CO-AC-05)', function () {
    it('is not changed by a corrected rate, which a new run reads', function () {
        trxPolicy();
        trxJanuaryRates();
        $first = trxRun()->assertCreated()->json('data');

        trxRate($this->closing, '2026-01-31', '0.00007');
        $again = $this->actingAs($this->admin)->getJson(trGroup("runs/{$first['id']}"))->assertOk()->json('data');
        $second = trxRun()->assertCreated()->json('data');

        expect(trxLine($again, '1000')['closing'])->toBe('1200.00')
            ->and(trxLine($second, '1000')['closing'])->toBe('1344.00')
            ->and($second['id'])->not->toBe($first['id']);
    });

    it('refuses to change the stored rows', function () {
        trxPolicy();
        trxJanuaryRates();
        $id = trxRun()->assertCreated()->json('data.id');

        expect(RunBalance::where('run_id', $id)->where('kind', 'translation_difference')->count())->toBe(2)
            ->and(fn () => DB::table('co_run_balances')->where('run_id', $id)->update(['debit' => '1']))->toThrow(Exception::class);
    });

    it('exports the translated lines and the difference to CSV', function () {
        trxPolicy();
        trxJanuaryRates();
        $id = trxRun()->assertCreated()->json('data.id');

        $csv = $this->actingAs($this->admin)->get(trGroup("runs/{$id}"), ['Accept' => 'text/csv'])->assertOk()->streamedContent();

        expect($csv)->toContain('3900')->toContain('Translation difference')->toContain('1200.00');
    });
});
