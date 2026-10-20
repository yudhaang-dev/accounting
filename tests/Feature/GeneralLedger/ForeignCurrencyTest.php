<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = fxAccountId($this->companyId, '1112');
    $this->sales = fxAccountId($this->companyId, '4110');
    $this->fxRounding = fxAccountId($this->companyId, '7230');

    mapExchangeDifferenceRoles($this->admin, $this->companyId);

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'central_bank', 'label' => 'Central bank'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/exchange-rates", [
        'rate_type' => 'central_bank', 'currency' => 'USD', 'effective_date' => '2026-03-10', 'rate' => '15000',
    ])->assertCreated();
});

function fxAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function fxPolicy(string $tolerance, int $version = 0)
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/ledger-policy', [
        'lock_version' => $version,
        'soft_closed_transaction_type_keys' => [],
        'soft_closed_permission' => null,
        'source_override_permission' => null,
        'fx_rounding_tolerance' => $tolerance,
    ]);
}

/** @param  list<PostJournalLine>  $lines */
function fxCommand(array $lines, string $date = '2026-03-15'): PostJournal
{
    return new PostJournal(companyId: test()->companyId, accountingDate: $date, description: 'FX', source: JournalSource::Entry, lines: $lines);
}

function fxPost(PostJournal $command): PostedJournal
{
    return app(PostingService::class)->post($command);
}

function fxFailure(PostJournal $command): ApiException|ValidationException
{
    try {
        app(PostingService::class)->post($command);
    } catch (ApiException|ValidationException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the posting to fail.');
}

/** USD 1000 received on the bank, credited to sales in rupiah. */
function usdSale(): PostJournal
{
    return fxCommand([
        new PostJournalLine(test()->bank, '1000.00', '0', currency: 'USD', rateTypeKey: 'central_bank'),
        new PostJournalLine(test()->sales, '0', '15000000.00'),
    ]);
}

/** Two USD credits that round apart from the one USD debit at rate 1.005 (0.01 short). */
function roundingCommand(): PostJournal
{
    return fxCommand([
        new PostJournalLine(test()->bank, '33.33', '0', currency: 'USD', exchangeRate: '1.005'),
        new PostJournalLine(test()->sales, '0', '16.66', currency: 'USD', exchangeRate: '1.005'),
        new PostJournalLine(test()->sales, '0', '16.67', currency: 'USD', exchangeRate: '1.005'),
    ]);
}

describe('foreign currency lines (GL-FR-08, GL-AC-05)', function () {
    it('posts USD 1000 at rate 15000 as 15,000,000 functional and keeps the transaction columns', function () {
        $posted = fxPost(usdSale());

        $line = DB::table('gl_journal_lines')->where('journal_id', $posted->id)->where('account_id', $this->bank)->first();
        $rateType = DB::table('md_rate_types')->where('company_id', $this->companyId)->where('key', 'central_bank')->value('id');

        expect($line->currency)->toBe('USD')
            ->and((float) $line->debit)->toBe(15000000.0)
            ->and((float) $line->transaction_amount)->toBe(1000.0)
            ->and((float) $line->exchange_rate)->toBe(15000.0)
            ->and($line->rate_type_id)->toBe($rateType)
            ->and($line->rate_date)->toBe('2026-03-10');

        $functionalLine = DB::table('gl_journal_lines')->where('journal_id', $posted->id)->where('account_id', $this->sales)->first();
        expect($functionalLine->currency)->toBe('IDR')->and((float) $functionalLine->exchange_rate)->toBe(1.0)->and($functionalLine->rate_type_id)->toBeNull();
    });

    it('uses the latest rate on or before the accounting date and records its date', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/exchange-rates", [
            'rate_type' => 'central_bank', 'currency' => 'USD', 'effective_date' => '2026-03-20', 'rate' => '15200',
        ])->assertCreated();

        $early = fxPost(fxCommand(usdSale()->lines, '2026-03-15'));
        $late = fxPost(fxCommand([
            new PostJournalLine($this->bank, '1000.00', '0', currency: 'USD', rateTypeKey: 'central_bank'),
            new PostJournalLine($this->sales, '0', '15200000.00'),
        ], '2026-03-25'));

        $rates = fn (PostedJournal $journal) => DB::table('gl_journal_lines')->where('journal_id', $journal->id)->where('currency', 'USD')->first(['exchange_rate', 'rate_date']);

        expect((float) $rates($early)->exchange_rate)->toBe(15000.0)->and($rates($early)->rate_date)->toBe('2026-03-10')
            ->and((float) $rates($late)->exchange_rate)->toBe(15200.0)->and($rates($late)->rate_date)->toBe('2026-03-20');
    });

    it('takes an explicit rate without a rate type or date', function () {
        $posted = fxPost(fxCommand([
            new PostJournalLine($this->bank, '10.00', '0', currency: 'USD', exchangeRate: '15100.5'),
            new PostJournalLine($this->sales, '0', '151005.00'),
        ]));

        $line = DB::table('gl_journal_lines')->where('journal_id', $posted->id)->where('currency', 'USD')->first();

        expect((float) $line->debit)->toBe(151005.0)->and($line->rate_type_id)->toBeNull()->and($line->rate_date)->toBeNull();
    });

    it('never guesses a rate: a missing rate and a missing instruction are refused', function () {
        $failure = fxFailure(fxCommand(usdSale()->lines, '2026-03-05'));
        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('EXCHANGE_RATE_NOT_FOUND');

        $failure = fxFailure(fxCommand([
            new PostJournalLine($this->bank, '1000.00', '0', currency: 'USD'),
            new PostJournalLine($this->sales, '0', '15000000.00'),
        ]));
        expect($failure)->toBeInstanceOf(ValidationException::class)->and($failure->errors())->toHaveKey('lines.0.exchange_rate');

        foreach (['0', '-5', 'abc', '1.1234567890123'] as $rate) {
            $failure = fxFailure(fxCommand([
                new PostJournalLine($this->bank, '1.00', '0', currency: 'USD', exchangeRate: $rate),
                new PostJournalLine($this->sales, '0', '1.00'),
            ]));
            expect($failure)->toBeInstanceOf(ValidationException::class)->and($failure->errors())->toHaveKey('lines.0.exchange_rate');
        }

        expect(DB::table('gl_journals')->count())->toBe(0);
    });

    it('refuses a currency the company has not activated and amounts beyond the currency scale', function () {
        $failure = fxFailure(fxCommand([
            new PostJournalLine($this->bank, '1.00', '0', currency: 'EUR', exchangeRate: '17000'),
            new PostJournalLine($this->sales, '0', '17000.00'),
        ]));
        expect($failure)->toBeInstanceOf(ValidationException::class)->and($failure->errors()['lines.0.currency'][0])->toContain('EUR');

        $failure = fxFailure(fxCommand([
            new PostJournalLine($this->bank, '1.005', '0', currency: 'USD', exchangeRate: '15000'),
            new PostJournalLine($this->sales, '0', '15075.00'),
        ]));
        expect($failure)->toBeInstanceOf(ValidationException::class)->and($failure->errors())->toHaveKey('lines.0.debit');
    });

    it('still refuses a foreign line whose functional counterpart does not match', function () {
        $failure = fxFailure(fxCommand([
            new PostJournalLine($this->bank, '1000.00', '0', currency: 'USD', rateTypeKey: 'central_bank'),
            new PostJournalLine($this->sales, '0', '14999999.00'),
        ]));

        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('UNBALANCED_ENTRY');
    });
});

describe('rounding difference of a conversion (GL-FR-05)', function () {
    it('books a difference within the tolerance to the fx_rounding account', function () {
        fxPolicy('0.01')->assertOk()->assertJsonPath('data.fx_rounding_tolerance', '0.01');

        $posted = fxPost(roundingCommand());
        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted->id)->orderBy('line_number')->get();

        expect($lines)->toHaveCount(4)
            ->and($lines[3]->account_id)->toBe($this->fxRounding)
            ->and((float) $lines[3]->credit)->toBe(0.01)
            ->and($lines[3]->currency)->toBe('IDR')
            ->and((float) $lines[3]->transaction_amount)->toBe(0.01)
            ->and(round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 6))->toBe(0.0);
    });

    it('books a negative difference to the other side', function () {
        fxPolicy('0.01')->assertOk();

        // Debit 16.66 + 16.67 converts to 33.49, the single credit of 33.33 converts to 33.50.
        $posted = fxPost(fxCommand([
            new PostJournalLine($this->bank, '16.66', '0', currency: 'USD', exchangeRate: '1.005'),
            new PostJournalLine($this->bank, '16.67', '0', currency: 'USD', exchangeRate: '1.005'),
            new PostJournalLine($this->sales, '0', '33.33', currency: 'USD', exchangeRate: '1.005'),
        ]));

        $rounding = DB::table('gl_journal_lines')->where('journal_id', $posted->id)->where('account_id', $this->fxRounding)->first();
        expect((float) $rounding->debit)->toBe(0.01)->and((float) $rounding->credit)->toBe(0.0);
    });

    it('refuses a difference above the tolerance, and any difference when the tolerance is zero', function () {
        $failure = fxFailure(roundingCommand());
        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('UNBALANCED_ENTRY');

        fxPolicy('0.005')->assertOk();
        $failure = fxFailure(roundingCommand());
        expect($failure->errorCode->value)->toBe('UNBALANCED_ENTRY')->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('never rounds a difference in a journal without foreign lines', function () {
        fxPolicy('5')->assertOk();

        $failure = fxFailure(fxCommand([
            new PostJournalLine($this->bank, '1000.00', '0'),
            new PostJournalLine($this->sales, '0', '999.00'),
        ]));

        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('UNBALANCED_ENTRY');
    });
});

describe('rounding without a mapping (X-AC-03)', function () {
    it('asks for the fx_rounding mapping when it is needed and its account is no longer a valid target', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/{$this->fxRounding}/deactivate")->assertOk();
        fxPolicy('0.01')->assertOk();

        $failure = fxFailure(roundingCommand());

        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('MAPPING_REQUIRED')
            ->and(DB::table('gl_journals')->count())->toBe(0);
    });
});

describe('balances per transaction currency (GL-FR-08)', function () {
    it('keeps USD and functional balances apart in the aggregated balances and the reader', function () {
        fxPost(usdSale());
        fxPost(fxCommand([
            new PostJournalLine($this->bank, '0', '250.00', currency: 'USD', exchangeRate: '15000'),
            new PostJournalLine($this->sales, '3750000.00', '0'),
        ], '2026-03-20'));
        fxPost(fxCommand([
            new PostJournalLine($this->bank, '500000.00', '0'),
            new PostJournalLine($this->sales, '0', '500000.00'),
        ], '2026-03-21'));

        $balances = DB::table('gl_account_balances')->where('account_id', $this->bank)->orderBy('currency')->get();

        expect($balances->pluck('currency')->all())->toBe(['IDR', 'USD'])
            ->and((float) $balances[1]->transaction_debit)->toBe(1000.0)
            ->and((float) $balances[1]->transaction_credit)->toBe(250.0)
            ->and((float) $balances[1]->debit)->toBe(15000000.0)
            ->and((float) $balances[0]->transaction_debit)->toBe(500000.0);

        $rows = app(LedgerReader::class)->currencyBalances($this->companyId, '2026-03-31', $this->bank);

        expect($rows)->toHaveCount(2)
            ->and([$rows[0]->currency, $rows[0]->debit, $rows[0]->credit])->toBe(['IDR', '500000.00', '0.00'])
            ->and([$rows[1]->currency, $rows[1]->debit, $rows[1]->credit, $rows[1]->functionalDebit, $rows[1]->functionalCredit])
            ->toBe(['USD', '1000.00', '250.00', '15000000.00', '3750000.00']);

        // As of an earlier date the later movements are not in the balance.
        expect(app(LedgerReader::class)->currencyBalances($this->companyId, '2026-03-16', $this->bank))->toHaveCount(1);
    });
});

describe('deactivating a currency that was used (MD-FR-06)', function () {
    it('refuses it once a journal line carries the currency, and allows it before', function () {
        mapExchangeDifferenceRoles($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'EUR'])->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies/EUR/deactivate")->assertOk();

        fxPost(usdSale());

        $this->actingAs($this->admin)->postJson("{$this->url}/currencies/USD/deactivate")
            ->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });
});

describe('currency balances endpoint (GL-FR-08)', function () {
    it('lists the balance of each account per transaction currency as of a date', function () {
        fxPost(usdSale());
        fxPost(fxCommand([
            new PostJournalLine($this->sales, '250000.00', '0'),
            new PostJournalLine($this->bank, '0', '250000.00'),
        ], '2026-03-21'));

        $data = $this->actingAs($this->admin)->getJson("{$this->url}/currency-balances?as_of=2026-03-31&account_id={$this->bank}")->assertOk()->json('data');

        expect($data['restricted'])->toBeFalse()
            ->and(array_column($data['balances'], 'currency'))->toBe(['IDR', 'USD'])
            ->and($data['balances'][1])->toMatchArray(['code' => '1112', 'debit' => '1000.00', 'credit' => '0.00', 'functional_debit' => '15000000.00', 'functional_credit' => '0.00'])
            ->and($data['balances'][0])->toMatchArray(['currency' => 'IDR', 'debit' => '0.00', 'credit' => '250000.00']);

        $earlier = $this->actingAs($this->admin)->getJson("{$this->url}/currency-balances?as_of=2026-03-16")->assertOk()->json('data.balances');

        expect(collect($earlier)->where('code', '1112')->pluck('currency')->all())->toBe(['USD'])
            ->and(array_column($earlier, 'code'))->toContain('4110');
    });

    it('needs as_of, finds only accounts of the company and asks for journals.read', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/currency-balances")->assertUnprocessable()->assertJsonValidationErrors(['as_of']);
        $this->actingAs($this->admin)->getJson("{$this->url}/currency-balances?as_of=2026-03-31&account_id=".Str::ulid())->assertNotFound();

        $outsider = memberWithRoles($this->companyId, 'viewer');
        $this->actingAs($outsider)->getJson("{$this->url}/currency-balances?as_of=2026-03-31")->assertOk();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("{$this->url}/currency-balances?as_of=2026-03-31")->assertForbidden();
    });
});
