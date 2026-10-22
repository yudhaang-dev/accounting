<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Reporting\Contracts\ReportPackage;
use App\Modules\Reporting\Contracts\ReportPackages;
use App\Platform\Api\ApiException;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    pkgPost($this->companyId, '2026-01-10', '1112', '4110', '1000.00');
    pkgPost($this->companyId, '2026-02-05', '1112', '4110', '500.00');
    pkgPost($this->companyId, '2026-02-20', '6220', '1112', '200.00');
});

function pkgAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function pkgPost(string $companyId, string $date, string $debitCode, string $creditCode, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [new PostJournalLine(pkgAccountId($companyId, $debitCode), $amount, '0'), new PostJournalLine(pkgAccountId($companyId, $creditCode), '0', $amount)],
    ));
}

function pkgBuild(string $companyId, string $from, string $to): ReportPackage
{
    return app(ReportPackages::class)->build($companyId, $from, $to);
}

function pkgBalance(ReportPackage $package, string $code): object
{
    return collect($package->balances)->firstWhere('code', $code);
}

describe('report package (CO-FR-03)', function () {
    it('carries the balances per account with the range, cutoff, currency and sources', function () {
        $package = pkgBuild($this->companyId, '2026-02-01', '2026-02-28');

        expect($package->companyId)->toBe($this->companyId)
            ->and([$package->from, $package->to, $package->cutoffDate])->toBe(['2026-02-01', '2026-02-28', '2026-02-28'])
            ->and($package->functionalCurrency)->toBe('IDR')
            ->and($package->balanced)->toBeTrue()
            ->and($package->sourceReferences)->toMatchArray(['report' => 'trial_balance', 'conversion_batch_id' => null])
            ->and($package->sourceReferences['parameters'])->toMatchArray(['from' => '2026-02-01', 'to' => '2026-02-28']);

        $bank = pkgBalance($package, '1112');
        expect([$bank->openingDebit, $bank->debit, $bank->credit, $bank->closingDebit, $bank->closingCredit])->toBe(['1000.00', '500.00', '200.00', '1300.00', '0.00']);

        $sales = pkgBalance($package, '4110');
        expect([$sales->openingCredit, $sales->credit, $sales->closingCredit, $sales->class])->toBe(['1000.00', '500.00', '1500.00', 'income']);
    });

    it('reads the whole company whoever is signed in, and only that company', function () {
        $other = User::factory()->create();
        [$otherCompanyId] = createWorkspaceAs($other, 'Other');
        confirmChartOfAccounts($other, $otherCompanyId);
        setUpFiscalCalendar($other, $otherCompanyId);
        pkgPost($otherCompanyId, '2026-02-10', '1112', '4110', '9999.00');

        $package = pkgBuild($this->companyId, '2026-01-01', '2026-02-28');
        $otherPackage = pkgBuild($otherCompanyId, '2026-01-01', '2026-02-28');

        expect(pkgBalance($package, '1112')->closingDebit)->toBe('1300.00')
            ->and(pkgBalance($otherPackage, '1112')->closingDebit)->toBe('9999.00')
            ->and(collect($package->balances)->pluck('accountId')->intersect(collect($otherPackage->balances)->pluck('accountId')))->toBeEmpty()
            ->and(app(CompanyContext::class)->has())->toBeFalse();
    });

    it('states the status of the books from the weakest period of the range', function () {
        $periods = $this->actingAs($this->admin)->getJson("{$this->url}/periods")->assertOk()->json('data');
        $byMonth = collect($periods)->keyBy(fn (array $period) => substr($period['start_date'], 0, 7));

        expect(pkgBuild($this->companyId, '2026-01-01', '2026-02-28')->bookStatus)->toBe('open');

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$byMonth['2026-01']['id']}/close")->assertOk();
        expect(pkgBuild($this->companyId, '2026-01-01', '2026-01-31')->bookStatus)->toBe('closed')
            ->and(pkgBuild($this->companyId, '2026-01-01', '2026-02-28')->bookStatus)->toBe('open');

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$byMonth['2026-02']['id']}/soft-close", ['reason' => 'review'])->assertOk();
        $package = pkgBuild($this->companyId, '2026-01-01', '2026-02-28');

        expect($package->bookStatus)->toBe('soft_closed')
            ->and($package->isClosed())->toBeFalse()
            ->and(array_map(fn ($period) => $period->status, $package->periods))->toBe(['closed', 'soft_closed']);

        expect(pkgBuild($this->companyId, '2027-06-02', '2027-06-20')->bookStatus)->toBe('no_periods');
    });

    it('reports history the ledger does not hold as unavailable, not as zero', function () {
        $migrated = User::factory()->create();
        [$companyId] = createWorkspaceAs($migrated, 'Migrated');
        confirmChartOfAccounts($migrated, $companyId);
        setUpFiscalCalendar($migrated, $companyId);

        $this->actingAs($migrated)->postJson("/api/v1/companies/{$companyId}/opening-balance-imports", [
            'source_key' => 'legacy-opening',
            'cutover_date' => '2026-06-01',
            'ledger_start_date' => '2026-04-01',
            'account_mapping' => [['source_code' => 'A', 'account_code' => '1111'], ['source_code' => 'E', 'account_code' => '3110']],
            'lines' => [['source_account_code' => 'A', 'debit' => '100.00', 'credit' => '0'], ['source_account_code' => 'E', 'debit' => '0', 'credit' => '100.00']],
        ])->assertCreated();

        $before = pkgBuild($companyId, '2026-03-01', '2026-03-31');
        $after = pkgBuild($companyId, '2026-04-01', '2026-06-30');

        expect($before->coverageStatus)->toBe('unavailable')
            ->and($before->isComplete())->toBeFalse()
            ->and($before->coverageFrom)->toBeNull()
            ->and($after->coverageStatus)->toBe('complete')
            ->and($after->sourceReferences['conversion_batch_id'])->not->toBeNull()
            ->and(pkgBuild($this->companyId, '2026-01-01', '2026-02-28')->coverageStatus)->toBe('complete');
    });

    it('names the version of the cash mapping in force, or none', function () {
        expect(pkgBuild($this->companyId, '2026-01-01', '2026-02-28')->mappingVersions)->toBe(['cash_equivalents' => null]);

        $this->actingAs($this->admin)->putJson("{$this->url}/reporting/account-role-mappings/cash_equivalents", [
            'lock_version' => 0,
            'account_ids' => [pkgAccountId($this->companyId, '1112')],
            'effective_date' => '2026-01-01',
        ])->assertOk();

        expect(pkgBuild($this->companyId, '2026-01-01', '2026-02-28')->mappingVersions)->toBe(['cash_equivalents' => 1]);
    });

    it('refuses an invalid range and an unknown company', function () {
        expect(fn () => pkgBuild($this->companyId, '2026-02-30', '2026-03-01'))->toThrow(ValidationException::class);
        expect(fn () => pkgBuild($this->companyId, '2026-03-01', '2026-02-01'))->toThrow(ValidationException::class);
        expect(fn () => pkgBuild((string) Str::ulid(), '2026-01-01', '2026-02-01'))->toThrow(ApiException::class);
    });
});
