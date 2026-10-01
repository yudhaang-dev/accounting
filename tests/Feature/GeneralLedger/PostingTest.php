<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\AccountBalance;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\GeneralLedger\Domain\JournalLine;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Platform\Api\ApiException;
use App\Platform\Retention\RetentionGuard;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = accountId($this->companyId, '1112');
    $this->sales = accountId($this->companyId, '4110');
    $this->expense = accountId($this->companyId, '6220');
    $this->cash = accountId($this->companyId, '1111');
});

function accountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

/** A balanced two-line journal: debit `$debitAccount`, credit `$creditAccount`. */
function journalCommand(string $companyId, string $debitAccount, string $creditAccount, string $amount = '1000.00', array $overrides = []): PostJournal
{
    return new PostJournal(...array_merge([
        'companyId' => $companyId,
        'accountingDate' => '2026-03-15',
        'description' => 'Test journal',
        'source' => JournalSource::Entry,
        'lines' => [
            new PostJournalLine($debitAccount, $amount, '0'),
            new PostJournalLine($creditAccount, '0', $amount),
        ],
    ], $overrides));
}

function postJournal(PostJournal $command): PostedJournal
{
    return app(PostingService::class)->post($command);
}

/** The ApiException a posting fails with. */
function postingFailure(PostJournal $command): ApiException
{
    try {
        app(PostingService::class)->post($command);
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the posting to fail.');
}

function journalCount(): int
{
    return DB::table('gl_journals')->count();
}

describe('posting a balanced journal (GL-AC-01)', function () {
    it('posts debit 1000 and credit 1000 and stores the journal with its lines', function () {
        $posted = postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000', [
            'documentDate' => '2026-03-14',
            'transactionTypeKey' => 'general_journal',
            'transactionTypeVersion' => 3,
            'references' => ['source_document_number' => 'INV-1'],
            'entryId' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'postedBy' => $this->admin->id,
        ]));

        expect($posted->number)->toBe('JU/2026/03/000001')->and($posted->replayed)->toBeFalse();

        $journal = Journal::withoutGlobalScope(CompanyScope::class)
            ->with(['lines' => fn ($lines) => $lines->withoutGlobalScope(CompanyScope::class)])
            ->findOrFail($posted->id);

        expect($journal->company_id)->toBe($this->companyId)
            ->and($journal->accounting_date->toDateString())->toBe('2026-03-15')
            ->and($journal->document_date->toDateString())->toBe('2026-03-14')
            ->and($journal->source)->toBe('entry')
            ->and($journal->transaction_type_key)->toBe('general_journal')
            ->and($journal->transaction_type_version)->toBe(3)
            ->and($journal->source_references)->toBe(['source_document_number' => 'INV-1'])
            ->and($journal->posted_by)->toBe($this->admin->id)
            ->and($journal->period_id)->toBe($posted->periodId)
            ->and($journal->lines)->toHaveCount(2);

        [$debit, $credit] = [$journal->lines[0], $journal->lines[1]];

        expect($debit->account_id)->toBe($this->bank)
            ->and($debit->debit->toScale(2)->toString())->toBe('1000.00')
            ->and($debit->credit->isZero())->toBeTrue()
            ->and($debit->currency)->toBe('IDR')
            ->and($debit->transaction_amount->toScale(2)->toString())->toBe('1000.00')
            ->and($debit->exchange_rate->toScale(0)->toString())->toBe('1')
            ->and($credit->account_id)->toBe($this->sales)
            ->and($credit->credit->toScale(2)->toString())->toBe('1000.00');
    });

    it('refuses debit 1000 and credit 999 without any partial posting', function () {
        $command = journalCommand($this->companyId, $this->bank, $this->sales, '1000', [
            'lines' => [new PostJournalLine($this->bank, '1000', '0'), new PostJournalLine($this->sales, '0', '999')],
        ]);

        $failure = postingFailure($command);

        expect($failure->errorCode->value)->toBe('UNBALANCED_ENTRY')
            ->and($failure->errors['lines'][0])->toContain('1000')->toContain('999')
            ->and(journalCount())->toBe(0)
            ->and(DB::table('gl_journal_lines')->count())->toBe(0)
            ->and(DB::table('gl_account_balances')->count())->toBe(0);

        // The failed posting did not burn a number.
        expect(postJournal(journalCommand($this->companyId, $this->bank, $this->sales))->number)->toBe('JU/2026/03/000001');
    });

    it('accepts several lines per account and amounts with trailing zeros', function () {
        $posted = postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '0', [
            'lines' => [
                new PostJournalLine($this->bank, '600.500', '0'),
                new PostJournalLine($this->cash, '399.5', '0'),
                new PostJournalLine($this->sales, '0', '1000'),
            ],
        ]));

        expect(DB::table('gl_journal_lines')->where('journal_id', $posted->id)->count())->toBe(3);
    });
});

describe('what a posting is refused for (GL-AC-02)', function () {
    it('refuses a closed period and a date outside the calendar with PERIOD_NOT_OPEN', function () {
        $march = DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-03')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$march}/close")->assertOk();

        $closed = postingFailure(journalCommand($this->companyId, $this->bank, $this->sales));
        $outside = postingFailure(journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['accountingDate' => '2027-03-15']));

        expect($closed->errorCode->value)->toBe('PERIOD_NOT_OPEN')
            ->and($closed->errors['accounting_date'][0])->toContain('2026-03')->toContain('closed')
            ->and($outside->errorCode->value)->toBe('PERIOD_NOT_OPEN')
            ->and($outside->errors['accounting_date'][0])->toContain('2027-03-15')
            ->and(journalCount())->toBe(0);
    });

    it('refuses parent, inactive and foreign accounts with ACCOUNT_NOT_POSTABLE, naming the line', function () {
        $parent = accountId($this->companyId, '1100');
        $inactive = accountId($this->companyId, '1113');
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/{$inactive}/deactivate")->assertOk();

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        confirmChartOfAccounts($other, $otherCompany);
        $foreign = accountId($otherCompany, '1112');

        $failure = postingFailure(journalCommand($this->companyId, $this->bank, $this->sales, '1000', [
            'lines' => [
                new PostJournalLine($parent, '100', '0'),
                new PostJournalLine($inactive, '100', '0'),
                new PostJournalLine($foreign, '100', '0'),
                new PostJournalLine((string) Str::ulid(), '100', '0'),
                new PostJournalLine($this->sales, '0', '400'),
            ],
        ]));

        expect($failure->errorCode->value)->toBe('ACCOUNT_NOT_POSTABLE')
            ->and(array_keys($failure->errors))->toBe(['lines.0.account_id', 'lines.1.account_id', 'lines.2.account_id', 'lines.3.account_id'])
            ->and($failure->errors['lines.0.account_id'][0])->toContain('parent')
            ->and($failure->errors['lines.1.account_id'][0])->toContain('inactive')
            ->and($failure->errors['lines.2.account_id'][0])->toContain('does not exist');
    });

    it('refuses to post once the company is inactive', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk();

        expect(postingFailure(journalCommand($this->companyId, $this->bank, $this->sales))->errorCode->value)->toBe('COMPANY_READ_ONLY');
    });

    it('refuses a malformed command with field errors', function () {
        $postWith = fn (array $lines, array $overrides = []) => fn () => postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['lines' => $lines] + $overrides));

        expect($postWith([new PostJournalLine($this->bank, '1000', '0')]))->toThrow(ValidationException::class);
        expect($postWith([new PostJournalLine($this->bank, '10', '10'), new PostJournalLine($this->sales, '0', '0')]))->toThrow(ValidationException::class);
        expect($postWith([new PostJournalLine($this->bank, '-10', '0'), new PostJournalLine($this->sales, '0', '-10')]))->toThrow(ValidationException::class);
        expect($postWith([new PostJournalLine($this->bank, '10.005', '0'), new PostJournalLine($this->sales, '0', '10.005')]))->toThrow(ValidationException::class);
        expect($postWith([new PostJournalLine($this->bank, 'abc', '0'), new PostJournalLine($this->sales, '0', '1')]))->toThrow(ValidationException::class);
        expect($postWith([new PostJournalLine($this->bank, '10', '0', currency: 'USD'), new PostJournalLine($this->sales, '0', '10')]))->toThrow(ValidationException::class);
        expect($postWith([new PostJournalLine($this->bank, '10', '0'), new PostJournalLine($this->sales, '0', '10')], ['accountingDate' => '15-03-2026']))->toThrow(ValidationException::class);

        try {
            $postWith([new PostJournalLine($this->bank, '10.005', '0'), new PostJournalLine($this->sales, '0', '10.005')])();
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['lines.0.debit', 'lines.1.credit']);
        }

        expect(journalCount())->toBe(0);
    });

    it('refuses an unknown dimension set with DIMENSION_INVALID', function () {
        $failure = postingFailure(journalCommand($this->companyId, $this->bank, $this->sales, '1000', [
            'lines' => [new PostJournalLine($this->bank, '10', '0', dimensionSetId: (string) Str::ulid()), new PostJournalLine($this->sales, '0', '10')],
        ]));

        expect($failure->errorCode->value)->toBe('DIMENSION_INVALID')->and(array_keys($failure->errors))->toBe(['lines.0.dimension_set_id']);
    });

    it('validates without posting, numbering or touching balances', function () {
        app(PostingValidator::class)->validate(journalCommand($this->companyId, $this->bank, $this->sales));

        expect(journalCount())->toBe(0)
            ->and(DB::table('gl_account_balances')->count())->toBe(0)
            ->and(postJournal(journalCommand($this->companyId, $this->bank, $this->sales))->number)->toBe('JU/2026/03/000001');

        expect(fn () => app(PostingValidator::class)->validate(journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['accountingDate' => '2030-01-01'])))
            ->toThrow(ApiException::class);
    });
});

describe('posted journals are immutable (GL-AC-03)', function () {
    beforeEach(function () {
        $posted = postJournal(journalCommand($this->companyId, $this->bank, $this->sales));
        $this->journalId = $posted->id;
        $this->lineId = (string) DB::table('gl_journal_lines')->where('journal_id', $posted->id)->value('id');
    });

    it('fails early in the models', function () {
        $journal = Journal::withoutGlobalScope(CompanyScope::class)->findOrFail($this->journalId);
        $line = JournalLine::withoutGlobalScope(CompanyScope::class)->findOrFail($this->lineId);

        expect(fn () => $journal->update(['description' => 'changed']))->toThrow(LogicException::class, 'immutable')
            ->and(fn () => $line->update(['description' => 'changed']))->toThrow(LogicException::class, 'immutable')
            ->and(fn () => $journal->delete())->toThrow(LogicException::class)
            ->and(fn () => $line->delete())->toThrow(LogicException::class);
    });

    it('refuses updates by direct query', function () {
        expect(fn () => DB::table('gl_journals')->where('id', $this->journalId)->update(['description' => 'changed']))
            ->toThrow(QueryException::class, 'immutable');
    });

    it('refuses deletes by direct query', function () {
        expect(fn () => DB::table('gl_journal_lines')->where('id', $this->lineId)->delete())->toThrow(QueryException::class);
    });

    it('refuses changing the amount of a line by direct query', function () {
        expect(fn () => DB::table('gl_journal_lines')->where('id', $this->lineId)->update(['debit' => 1]))->toThrow(QueryException::class, 'immutable');
    });

    it('protects the ledger tables against hard deletes', function () {
        expect(RetentionGuard::protectedTables())->toContain('gl_journals', 'gl_journal_lines', 'gl_account_balances')
            ->and(RetentionGuard::unprotectedFinancialTables())->toBe([]);
    });
});

describe('balance is enforced by the database (GL-AC-01)', function () {
    function insertLine(string $companyId, array $journal, int $number, string $account, string $debit, string $credit): void
    {
        DB::table('gl_journal_lines')->insert([
            'id' => (string) Str::ulid(), 'company_id' => $companyId, 'journal_id' => $journal['id'], 'line_number' => $number,
            'account_id' => $account, 'period_id' => $journal['period_id'], 'accounting_date' => '2026-03-15',
            'debit' => $debit, 'credit' => $credit, 'currency' => 'IDR', 'transaction_amount' => max($debit, $credit),
            'dimension_set_id' => app(DimensionSets::class)->emptySet($companyId), 'created_at' => now(),
        ]);
    }

    function insertJournal(string $companyId): array
    {
        $journal = [
            'id' => (string) Str::ulid(),
            'period_id' => (string) DB::table('gl_periods')->where('company_id', $companyId)->where('name', '2026-03')->value('id'),
        ];

        DB::table('gl_journals')->insert([
            'id' => $journal['id'], 'company_id' => $companyId, 'number' => 'RAW-'.$journal['id'], 'accounting_date' => '2026-03-15',
            'period_id' => $journal['period_id'], 'description' => 'raw', 'source' => 'system', 'posted_at' => now(), 'created_at' => now(),
        ]);

        return $journal;
    }

    it('refuses an unbalanced journal at commit time', function () {
        $journal = insertJournal($this->companyId);
        insertLine($this->companyId, $journal, 1, $this->bank, '100', '0');
        insertLine($this->companyId, $journal, 2, $this->sales, '0', '99');

        expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toThrow(QueryException::class, 'not balanced');
    });

    it('refuses a journal with fewer than two lines', function () {
        $journal = insertJournal($this->companyId);
        insertLine($this->companyId, $journal, 1, $this->bank, '100', '0');

        expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toThrow(QueryException::class, 'at least two lines');
    });

    it('accepts a balanced journal and refuses a line with both sides or none', function () {
        $journal = insertJournal($this->companyId);
        insertLine($this->companyId, $journal, 1, $this->bank, '100', '0');
        insertLine($this->companyId, $journal, 2, $this->sales, '0', '100');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        expect(DB::table('gl_journal_lines')->where('journal_id', $journal['id'])->count())->toBe(2);

        expect(fn () => insertLine($this->companyId, $journal, 3, $this->sales, '5', '5'))->toThrow(QueryException::class, 'one_side');
    });
});

describe('journal numbers (GL-AC-12)', function () {
    it('are sequential without gaps and restart every month', function () {
        $numbers = [];

        foreach (['2026-03-01', '2026-03-31', '2026-04-01', '2026-03-15'] as $date) {
            $numbers[] = postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '10', ['accountingDate' => $date]))->number;
        }

        expect($numbers)->toBe(['JU/2026/03/000001', 'JU/2026/03/000002', 'JU/2026/04/000001', 'JU/2026/03/000003']);
    });

    it('are independent per company and unique per company', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        confirmChartOfAccounts($other, $otherCompany);
        setUpFiscalCalendar($other, $otherCompany);

        $mine = postJournal(journalCommand($this->companyId, $this->bank, $this->sales))->number;
        $theirs = postJournal(journalCommand($otherCompany, accountId($otherCompany, '1112'), accountId($otherCompany, '4110')))->number;

        expect($mine)->toBe($theirs)->toBe('JU/2026/03/000001');

        expect(fn () => DB::table('gl_journals')->where('company_id', $this->companyId)->get()->each(
            fn ($row) => DB::table('gl_journals')->insert([...(array) $row, 'id' => (string) Str::ulid(), 'entry_id' => null, 'reverses_journal_id' => null]),
        ))->toThrow(QueryException::class);
    });

    it('stay gapless when a posting fails after the number was issued', function () {
        $first = postJournal(journalCommand($this->companyId, $this->bank, $this->sales))->number;

        try {
            DB::transaction(function () {
                postJournal(journalCommand($this->companyId, $this->bank, $this->sales));

                throw new RuntimeException('the caller fails after posting');
            });
        } catch (RuntimeException) {
        }

        $third = postJournal(journalCommand($this->companyId, $this->bank, $this->sales))->number;

        expect($first)->toBe('JU/2026/03/000001')->and($third)->toBe('JU/2026/03/000002')->and(journalCount())->toBe(2);
    });
});

describe('posting the same entry twice (GL-FR-05)', function () {
    it('returns the journal that exists and does not double the balances', function () {
        $command = journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['entryId' => 'entry-1']);

        $first = postJournal($command);
        $again = postJournal($command);

        expect($again->id)->toBe($first->id)->and($again->number)->toBe($first->number)->and($again->replayed)->toBeTrue()
            ->and($first->replayed)->toBeFalse()
            ->and(journalCount())->toBe(1)
            ->and(DB::table('gl_journal_lines')->count())->toBe(2)
            ->and((string) DB::table('gl_account_balances')->where('account_id', $this->bank)->value('debit'))->toBe('1000.000000');
    });

    it('still returns the original after its period was closed', function () {
        $first = postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['entryId' => 'entry-2']));
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$first->periodId}/close")->assertOk();

        expect(postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['entryId' => 'entry-2']))->id)->toBe($first->id);
    });

    it('keeps entries of different companies apart and posts without an entry id every time', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        confirmChartOfAccounts($other, $otherCompany);
        setUpFiscalCalendar($other, $otherCompany);

        $mine = postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['entryId' => 'shared']));
        $theirs = postJournal(journalCommand($otherCompany, accountId($otherCompany, '1112'), accountId($otherCompany, '4110'), '1000', ['entryId' => 'shared']));

        expect($theirs->id)->not->toBe($mine->id)->and($theirs->replayed)->toBeFalse();

        $one = postJournal(journalCommand($this->companyId, $this->bank, $this->sales));
        $two = postJournal(journalCommand($this->companyId, $this->bank, $this->sales));

        expect($two->id)->not->toBe($one->id)->and(journalCount())->toBe(4);
    });

    it('runs inside the transaction of the caller and rolls back with it', function () {
        try {
            DB::transaction(function () {
                postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000', ['entryId' => 'entry-3']));

                throw new RuntimeException('caller fails');
            });
        } catch (RuntimeException) {
        }

        expect(journalCount())->toBe(0)->and(DB::table('gl_account_balances')->count())->toBe(0);
    });
});

describe('aggregated account balances (GL-FR-05)', function () {
    it('accumulates per account, period and currency, and always balances', function () {
        postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000'));
        postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '250.50'));
        postJournal(journalCommand($this->companyId, $this->expense, $this->bank, '100', ['accountingDate' => '2026-04-10']));

        $balances = DB::table('gl_account_balances')->where('company_id', $this->companyId)->get();
        $march = DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-03')->value('id');
        $row = fn (string $account, string $period) => $balances->first(fn ($b) => $b->account_id === $account && $b->period_id === $period);

        expect($balances)->toHaveCount(4)
            ->and((float) $row($this->bank, $march)->debit)->toBe(1250.5)
            ->and((float) $row($this->sales, $march)->credit)->toBe(1250.5)
            ->and((float) $row($this->bank, $march)->transaction_debit)->toBe(1250.5)
            ->and($row($this->bank, $march)->currency)->toBe('IDR')
            ->and((float) $balances->sum('debit'))->toBe((float) $balances->sum('credit'));

        $april = DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-04')->value('id');

        expect((float) $row($this->bank, $april)->credit)->toBe(100.0);
    });

    it('is readable through the model for the current company', function () {
        postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '1000'));

        $debit = app(CompanyContext::class)->run($this->companyId, fn () => AccountBalance::query()->where('account_id', $this->bank)->sole()->debit);

        expect($debit->toScale(2)->toString())->toBe('1000.00');
    });

    it('updates balance rows in a consistent key order whatever the order of the lines', function () {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, 'INSERT INTO gl_account_balances')) {
                $statements[] = $query->bindings[1];
            }
        });

        $accounts = [$this->bank, $this->sales, $this->expense, $this->cash];

        postJournal(journalCommand($this->companyId, $this->bank, $this->sales, '0', [
            'lines' => [
                new PostJournalLine($accounts[1], '0', '300'),
                new PostJournalLine($accounts[3], '100', '0'),
                new PostJournalLine($accounts[2], '100', '0'),
                new PostJournalLine($accounts[0], '100', '0'),
            ],
        ]));

        $sorted = $statements;
        sort($sorted, SORT_STRING);

        expect($statements)->toHaveCount(4)->and($statements)->toBe($sorted);
    });
});

describe('dimension sets and opaque metadata (ADR-0007)', function () {
    it('puts lines in the empty dimension set of the company, the same one every time', function () {
        postJournal(journalCommand($this->companyId, $this->bank, $this->sales));
        postJournal(journalCommand($this->companyId, $this->bank, $this->sales));

        $sets = DB::table('gl_journal_lines')->where('company_id', $this->companyId)->distinct()->pluck('dimension_set_id');
        $empty = app(DimensionSets::class)->emptySet($this->companyId);

        expect($sets->all())->toBe([$empty])
            ->and(DB::table('md_dimension_sets')->where('company_id', $this->companyId)->count())->toBe(1)
            ->and(app(DimensionSets::class)->exists($this->companyId, $empty))->toBeTrue()
            ->and(app(DimensionSets::class)->exists((string) Str::ulid(), $empty))->toBeFalse();

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        expect(app(DimensionSets::class)->emptySet($otherCompany))->not->toBe($empty);
    });

    it('aggregates balances per dimension set as well', function () {
        postJournal(journalCommand($this->companyId, $this->bank, $this->sales));

        expect(DB::table('gl_account_balances')->pluck('dimension_set_id')->unique()->all())
            ->toBe([app(DimensionSets::class)->emptySet($this->companyId)]);
    });
});

describe('accounts that were posted to (GL-FR-01)', function () {
    it('cannot become a parent, but can still be deactivated', function () {
        postJournal(journalCommand($this->companyId, $this->bank, $this->sales));
        $accounts = "{$this->url}/accounts";

        $this->actingAs($this->admin)->postJson($accounts, ['code' => '1112-1', 'name' => 'Sub bank', 'class' => 'asset', 'parent_id' => $this->bank])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $free = $this->actingAs($this->admin)->postJson($accounts, ['code' => '1999', 'name' => 'Free', 'class' => 'asset'])->assertCreated()->json('data');
        $this->actingAs($this->admin)->patchJson("{$accounts}/{$free['id']}", ['lock_version' => 1, 'parent_id' => $this->bank])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $this->actingAs($this->admin)->postJson("{$accounts}/{$this->sales}/deactivate")->assertOk();
    });
});
