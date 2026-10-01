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

    $this->bank = tbAccountId($this->companyId, '1112');
    $this->sales = tbAccountId($this->companyId, '4110');
    $this->expense = tbAccountId($this->companyId, '6220');

    tbPost($this->companyId, '2026-01-10', $this->bank, $this->sales, '1000.00');
    tbPost($this->companyId, '2026-02-05', $this->bank, $this->sales, '500.00');
    tbPost($this->companyId, '2026-02-20', $this->expense, $this->bank, '200.00');
    tbPost($this->companyId, '2026-03-05', $this->bank, $this->sales, '300.00');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function tbAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function tbPost(string $companyId, string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
    ));
}

function tbRow(array $rows, string $code): array
{
    return collect($rows)->firstWhere('code', $code);
}

describe('trial balance (RP-FR-05, RP-AC-01)', function () {
    it('shows opening balance, movement and closing balance per account', function () {
        $response = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-02-01&to=2026-02-28")->assertOk();

        $rows = $response->json('data.rows');
        expect(tbRow($rows, '1112'))->toMatchArray([
            'opening_debit' => '1000.00', 'opening_credit' => '0.00',
            'debit' => '500.00', 'credit' => '200.00',
            'closing_debit' => '1300.00', 'closing_credit' => '0.00',
        ]);
        expect(tbRow($rows, '4110'))->toMatchArray([
            'opening_debit' => '0.00', 'opening_credit' => '1000.00',
            'debit' => '0.00', 'credit' => '500.00',
            'closing_debit' => '0.00', 'closing_credit' => '1500.00',
        ]);
        expect(tbRow($rows, '6220'))->toMatchArray(['opening_debit' => '0.00', 'debit' => '200.00', 'closing_debit' => '200.00']);

        $response->assertJsonPath('data.functional_currency', 'IDR')
            ->assertJsonPath('data.parameters.from', '2026-02-01')
            ->assertJsonPath('data.balanced', true)
            ->assertJsonPath('data.totals.debit', '700.00')
            ->assertJsonPath('data.totals.credit', '700.00')
            ->assertJsonPath('data.totals.closing_debit', '1500.00')
            ->assertJsonPath('data.totals.closing_credit', '1500.00');
    });

    it('leaves out accounts without opening balance or movement and orders by code', function () {
        $march = collect($this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-03-01&to=2026-03-31")->json('data.rows'))->pluck('code')->all();
        expect($march)->toBe(['1112', '4110', '6220']);

        $january = collect($this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-01-31")->json('data.rows'))->pluck('code')->all();
        expect($january)->toBe(['1112', '4110']);
    });

    it('treats a range before any posting as empty and balanced', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2025-01-01&to=2025-12-31")
            ->assertOk()->assertJsonPath('data.rows', [])->assertJsonPath('data.balanced', true)->assertJsonPath('data.totals.debit', '0.00');
    });

    it('narrows to one account', function () {
        $rows = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31&account_id={$this->sales}")
            ->assertOk()->json('data.rows');

        expect($rows)->toHaveCount(1)->and($rows[0]['code'])->toBe('4110')->and($rows[0]['closing_credit'])->toBe('1800.00');
    });

    it('requires a valid range', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance")->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-03-01&to=2026-02-01")->assertUnprocessable()->assertJsonValidationErrors('to');
    });

    it('is not available to an outsider or without reports.read', function () {
        $this->actingAs(User::factory()->create())->getJson("{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31")->assertForbidden();
        $this->actingAs(memberWithRoles($this->companyId))->getJson("{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31")->assertForbidden();
    });
});

describe('CSV export (X-FR-02, API-AC-07)', function () {
    it('downloads the same report with Accept: text/csv', function () {
        $response = $this->actingAs($this->viewer)->get("{$this->url}/reports/trial-balance?from=2026-02-01&to=2026-02-28", ['Accept' => 'text/csv'])->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('text/csv');
        $lines = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));

        expect($lines[0])->toBe('code,name,class,opening_debit,opening_credit,debit,credit,closing_debit,closing_credit')
            ->and($lines[1])->toStartWith('1112,')->toEndWith('1000.00,0.00,500.00,200.00,1300.00,0.00')
            ->and(end($lines))->toBe(',Total,,1000.00,1000.00,700.00,700.00,1500.00,1500.00');
    });

    it('applies the same authorization and validation as the JSON report', function () {
        $this->actingAs(User::factory()->create())->get("{$this->url}/reports/trial-balance?from=2026-02-01&to=2026-02-28", ['Accept' => 'text/csv'])->assertForbidden();
        $this->actingAs($this->viewer)->get("{$this->url}/reports/trial-balance", ['Accept' => 'text/csv'])->assertUnprocessable();
    });
});

describe('drill-down to journals', function () {
    it('lists the journals that touch an account within the range, with their lines', function () {
        $response = $this->actingAs($this->viewer)
            ->getJson("{$this->url}/journals?filter[account_id]={$this->expense}&filter[from]=2026-02-01&filter[to]=2026-02-28")
            ->assertOk()->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.accounting_date', '2026-02-20')
            ->assertJsonPath('data.0.source', 'entry')
            ->assertJsonCount(2, 'data.0.lines')
            ->assertJsonPath('data.0.lines.0.debit', '200.00');

        $this->actingAs($this->viewer)->getJson("{$this->url}/journals?filter[account_id]={$this->bank}")->assertJsonCount(4, 'data');
        $this->actingAs($this->viewer)->getJson("{$this->url}/journals?filter[source]=bogus")->assertUnprocessable();
    });

    it('shows one journal and hides it from another company', function () {
        $id = $this->actingAs($this->viewer)->getJson("{$this->url}/journals")->json('data.0.id');
        $this->actingAs($this->viewer)->getJson("{$this->url}/journals/{$id}")->assertOk()->assertJsonPath('data.id', $id);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/journals/{$id}")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/journals")->assertOk()->assertJsonCount(0, 'data');
    });
});
