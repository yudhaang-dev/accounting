<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Platform\Api\ApiException;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = reversalAccountId($this->companyId, '1112');
    $this->sales = reversalAccountId($this->companyId, '4110');
    $this->expense = reversalAccountId($this->companyId, '6220');
});

function reversalAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

/** @param  array<string, string>|null  $dimensions */
function postSale(string $amount = '1000.00', string $date = '2026-03-15', ?array $dimensions = null, ?string $key = null): PostedJournal
{
    return app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Sale',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine(test()->bank, $amount, '0', dimensions: $dimensions),
            new PostJournalLine(test()->sales, '0', $amount, dimensions: $dimensions),
        ],
        transactionTypeKey: $key,
    ));
}

function reverseJournal(string $journalId, string $date = '2026-04-02', array $overrides = []): PostedJournal
{
    return app(PostingService::class)->reverse(new ReverseJournal(...array_merge([
        'companyId' => test()->companyId,
        'journalId' => $journalId,
        'accountingDate' => $date,
    ], $overrides)));
}

function reversalFailure(string $journalId, string $date = '2026-04-02', array $overrides = []): ApiException|ValidationException
{
    try {
        reverseJournal($journalId, $date, $overrides);
    } catch (ApiException|ValidationException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the reversal to fail.');
}

describe('reversing a journal (GL-FR-06)', function () {
    it('posts the mirror image on the given date and links it to the original', function () {
        $original = postSale();
        $reversal = reverseJournal($original->id, '2026-04-02', ['postedBy' => $this->admin->id]);

        $originalJournal = Journal::withoutGlobalScope(CompanyScope::class)->findOrFail($original->id);
        $reversalJournal = Journal::withoutGlobalScope(CompanyScope::class)->with(['lines' => fn ($lines) => $lines->withoutGlobalScope(CompanyScope::class)])->findOrFail($reversal->id);

        expect($reversal->replayed)->toBeFalse()
            ->and($reversal->number)->not->toBe($original->number)
            ->and($reversal->accountingDate)->toBe('2026-04-02')
            ->and($reversal->periodId)->not->toBe($original->periodId)
            ->and($reversalJournal->reverses_journal_id)->toBe($original->id)
            ->and($reversalJournal->description)->toBe("Reversal of journal {$original->number}")
            ->and($reversalJournal->posted_by)->toBe($this->admin->id)
            ->and($reversalJournal->source)->toBe($originalJournal->source)
            ->and($reversalJournal->lines)->toHaveCount(2);

        [$first, $second] = [$reversalJournal->lines[0], $reversalJournal->lines[1]];

        expect($first->account_id)->toBe($this->bank)
            ->and($first->credit->toScale(2)->toString())->toBe('1000.00')
            ->and($first->debit->isZero())->toBeTrue()
            ->and($second->account_id)->toBe($this->sales)
            ->and($second->debit->toScale(2)->toString())->toBe('1000.00')
            ->and($first->accounting_date->toDateString())->toBe('2026-04-02')
            ->and($first->period_id)->toBe($reversal->periodId);
    });

    it('leaves zero at every account, dimension set and currency once original and reversal are added (GL-AC-11)', function () {
        $type = collect($this->actingAs($this->admin)->getJson("{$this->url}/dimension-types")->json('data'))->firstWhere('key', 'department');
        $value = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type['id'], 'code' => 'SALES', 'name' => 'Sales'])->assertCreated()->json('data.id');

        $plain = postSale('1000.00');
        $tagged = postSale('250.50', dimensions: ['department' => $value]);

        // Same period, so the aggregated balances must cancel exactly too.
        reverseJournal($plain->id, '2026-03-20');
        reverseJournal($tagged->id, '2026-03-21');

        $sums = DB::table('gl_journal_lines')
            ->where('company_id', $this->companyId)
            ->groupBy('account_id', 'dimension_set_id', 'currency')
            ->selectRaw('sum(debit - credit) as net, sum(transaction_amount * (case when debit > 0 then 1 else -1 end)) as transaction_net')
            ->get();

        expect($sums)->toHaveCount(4)
            ->and($sums->every(fn ($row) => (float) $row->net === 0.0 && (float) $row->transaction_net === 0.0))->toBeTrue();

        $balances = DB::table('gl_account_balances')->where('company_id', $this->companyId)->get();

        expect($balances)->toHaveCount(4)
            ->and($balances->every(fn ($row) => (float) $row->debit === (float) $row->credit
                && (float) $row->transaction_debit === (float) $row->transaction_credit
                && (float) $row->debit > 0))->toBeTrue();
    });

    it('refuses a second reversal of the same journal and keeps the first', function () {
        $original = postSale();
        $first = reverseJournal($original->id);
        $count = DB::table('gl_journals')->count();

        $failure = reversalFailure($original->id, '2026-04-03');

        expect($failure)->toBeInstanceOf(ApiException::class)
            ->and($failure->errorCode->value)->toBe('INVALID_STATE_TRANSITION')
            ->and($failure->errors['journal_id'][0])->toContain($original->number)->toContain($first->number)
            ->and(DB::table('gl_journals')->count())->toBe($count)
            ->and(DB::table('gl_journals')->where('reverses_journal_id', $original->id)->count())->toBe(1);
    });

    it('returns the existing reversal when the same entry key is sent again', function () {
        $original = postSale();
        $first = reverseJournal($original->id, overrides: ['entryId' => 'REV-1']);
        $again = reverseJournal($original->id, '2026-04-09', ['entryId' => 'REV-1']);

        expect($again->replayed)->toBeTrue()
            ->and($again->id)->toBe($first->id)
            ->and($again->accountingDate)->toBe('2026-04-02')
            ->and(DB::table('gl_journals')->count())->toBe(2);
    });

    it('lets the reversal be reversed in turn, as a fresh posting of the original', function () {
        $original = postSale();
        $reversal = reverseJournal($original->id);

        $restated = reverseJournal($reversal->id, '2026-04-10');

        expect(Journal::withoutGlobalScope(CompanyScope::class)->with(['lines' => fn ($lines) => $lines->withoutGlobalScope(CompanyScope::class)])->findOrFail($restated->id)->lines[0]->debit->toScale(2)->toString())->toBe('1000.00')
            ->and(DB::table('gl_account_balances')->where('account_id', $this->bank)->sum('debit'))->toEqual(2000);
    });

    it('rejects an unknown journal and one of another company', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other);

        $failure = reversalFailure('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('NOT_FOUND');

        $original = postSale();
        $failure = reversalFailure($original->id, overrides: ['companyId' => $otherCompany]);
        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('NOT_FOUND');
    });

    it('rejects a malformed date and a date outside any period without storing anything', function () {
        $original = postSale();
        $count = DB::table('gl_journals')->count();

        expect(reversalFailure($original->id, '15-04-2026'))->toBeInstanceOf(ValidationException::class);

        $failure = reversalFailure($original->id, '2031-01-01');
        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('PERIOD_NOT_OPEN');

        expect(DB::table('gl_journals')->count())->toBe($count);
        // The refused attempts did not use the one reversal the journal is entitled to.
        expect(reverseJournal($original->id)->accountingDate)->toBe('2026-04-02');
    });

    it('rejects a closed period for the reversal date but not for the original', function () {
        $original = postSale();
        $april = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-04')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$april}/close")->assertOk();

        $failure = reversalFailure($original->id, '2026-04-02');
        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('PERIOD_NOT_OPEN');

        // March is still open, so the same journal can be reversed there.
        expect(reverseJournal($original->id, '2026-03-31')->accountingDate)->toBe('2026-03-31');
    });

    it('stays possible after the account of the original was deactivated', function () {
        $original = postSale();
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/{$this->sales}/deactivate")->assertOk();

        expect(reverseJournal($original->id)->number)->not->toBeEmpty();
    });

    it('is refused while the company is read-only', function () {
        $original = postSale();
        DB::table('md_companies')->where('id', $this->companyId)->update(['status' => 'inactive']);

        $failure = reversalFailure($original->id);

        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('COMPANY_READ_ONLY');
    });

    it('never touches the original: the database still rejects changing or deleting it', function () {
        $original = postSale();
        reverseJournal($original->id);

        expect(fn () => DB::table('gl_journals')->where('id', $original->id)->update(['description' => 'Edited']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('gl_journals')->where('id', $original->id)->delete())->toThrow(QueryException::class);
    });
});
