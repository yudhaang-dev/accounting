<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;

// Aging of receivables and payables by the buckets of the policy (SL-FR-08, SL-AC-04). The helpers to build types
// and entries are those of DocumentTest, AllocationTest and RealizedDifferenceTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');
    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->receivables = docAccountId('1121');
    $this->payables = docAccountId('2110');
    $this->bank = docAccountId('1112');
    $this->sales = docAccountId('4110');
    $this->gain = docAccountId('4130');
    $this->loss = docAccountId('6220');
    $this->expense = docAccountId('6220');

    foreach ([$this->receivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    mapExchangeDifferenceRoles($this->admin, $this->companyId);

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->customer = docParty(['name' => 'PT Maju Jaya']);
    $this->other = docParty(['name' => 'CV Berkah']);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');

    $usd = ['allowed_currencies' => ['USD'], 'allow_rate_input' => true];
    docType('credit_sale', docInvoiceBody());
    docType('receipt', docSettlingBody());
    docType('usd_sale', docInvoiceBody($usd));
});

function agingInvoice(string $number, string $total, string $due, ?string $party = null, string $date = '2026-03-15'): string
{
    docPosted(docEntry('credit_sale', ['party' => $party ?? test()->customer, 'number' => $number, 'due' => $due, 'total' => $total], ['accounting_date' => $date])->assertCreated()->json('data.id'));

    return (string) DB::table('sl_open_items')->where('document_number', $number)->value('id');
}

function agingReport(string $asOf, array $query = [], string $role = 'receivables_control', ?User $as = null)
{
    return test()->actingAs($as ?? test()->viewer)->getJson(test()->url.'/reports/aging?'.http_build_query(array_merge(['as_of' => $asOf, 'control_role' => $role], $query)));
}

describe('aging by the default policy (SL-FR-08)', function () {
    it('puts each open item in the bucket of its age from the due date, per party, with the drill-down', function () {
        agingInvoice('INV-1', '1000000', '2026-05-10');
        agingInvoice('INV-2', '500000', '2026-04-15');
        agingInvoice('INV-3', '300000', '2026-03-31', null, '2026-02-01');
        agingInvoice('INV-4', '200000', '2026-02-01', $this->other, '2026-01-15');

        $report = agingReport('2026-05-31')->assertOk()->json('data');

        expect($report['parameters'])->toMatchArray(['as_of' => '2026-05-31', 'control_role' => 'receivables_control', 'basis' => 'due_date'])
            ->and(array_column($report['parameters']['buckets'], 'key'))->toBe(['current', '1-30', '31-60', '61-90', 'over_90'])
            ->and($report['functional_currency'])->toBe('IDR')
            ->and($report['totals'])->toMatchArray(['current' => '0.000000', '1-30' => '1000000.000000', '31-60' => '500000.000000', '61-90' => '300000.000000', 'over_90' => '200000.000000', 'total' => '2000000.000000'])
            ->and(array_column($report['parties'], 'party_name'))->toBe(['CV Berkah', 'PT Maju Jaya']);

        $maju = $report['parties'][1];
        $item = collect($maju['items'])->firstWhere('document_number', 'INV-3');

        expect($maju['totals'])->toMatchArray(['1-30' => '1000000.000000', '31-60' => '500000.000000', '61-90' => '300000.000000', 'total' => '1800000.000000'])
            ->and($item)->toMatchArray(['kind' => 'invoice', 'due_date' => '2026-03-31', 'age_days' => 61, 'bucket' => '61-90', 'currency' => 'IDR', 'remaining_amount' => '300000.000000', 'remaining_functional' => '300000.000000'])
            ->and(DB::table('sl_open_items')->where('id', $item['open_item_id'])->value('document_id'))->toBe($item['document_id']);
    });

    it('keeps what is not due yet in the current bucket, and ages from the document date when told to', function () {
        agingInvoice('INV-1', '1000000', '2026-06-30');

        expect(agingReport('2026-05-31')->json('data.totals.current'))->toBe('1000000.000000');

        $report = agingReport('2026-05-31', ['basis' => 'document_date'])->assertOk()->json('data');

        expect($report['parameters']['basis'])->toBe('document_date')
            ->and($report['parties'][0]['items'][0])->toMatchArray(['age_days' => 77, 'bucket' => '61-90']);
    });

    it('shows what lowers the balance as negative amounts, such as an advance not applied yet', function () {
        agingInvoice('INV-1', '1000000', '2026-04-30');
        docPosted(docReceipt('300000')->assertCreated()->json('data.id'));

        $report = agingReport('2026-05-31')->assertOk()->json('data');
        $advance = collect($report['parties'][0]['items'])->firstWhere('kind', 'payment');

        expect($advance['remaining_amount'])->toBe('-300000.000000')
            ->and($report['totals']['total'])->toBe('700000.000000');
    });

    it('ages payables apart from receivables, an invoice of the supplier being positive', function () {
        $supplier = docParty(['name' => 'UD Pemasok', 'is_customer' => false, 'is_supplier' => true]);
        docType('credit_purchase', docInvoiceBody([
            'posting_rules' => [
                ['side' => 'credit', 'account' => docControl()],
                ['side' => 'debit', 'account' => docFixed($this->expense)],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control'],
        ]));
        docPosted(docEntry('credit_purchase', ['party' => $supplier, 'number' => 'BILL-1', 'due' => '2026-04-30', 'total' => '800000'])->assertCreated()->json('data.id'));
        agingInvoice('INV-1', '1000000', '2026-04-30');

        $payables = agingReport('2026-05-31', [], 'payables_control')->assertOk()->json('data');

        expect($payables['totals']['total'])->toBe('800000.000000')
            ->and($payables['parties'])->toHaveCount(1)
            ->and($payables['parties'][0]['items'][0])->toMatchArray(['document_number' => 'BILL-1', 'remaining_amount' => '800000.000000']);
    });

    it('narrows to one party', function () {
        agingInvoice('INV-1', '1000000', '2026-04-30');
        agingInvoice('INV-2', '200000', '2026-04-30', $this->other);

        $report = agingReport('2026-05-31', ['party_id' => $this->other])->assertOk()->json('data');

        expect($report['parties'])->toHaveCount(1)->and($report['totals']['total'])->toBe('200000.000000');
    });
});

describe('aging on a past date (SL-AC-04)', function () {
    it('counts only the allocations dated up to the date, and not the documents recorded after it', function () {
        $invoice = agingInvoice('INV-1', '1000000', '2026-04-14');
        $id = docEntry('receipt', ['party' => $this->customer, 'bank' => $this->cashAccount, 'total' => '600000', 'allocs' => [['open_item_id' => $invoice, 'amount' => '600000']]], ['accounting_date' => '2026-05-10'])->assertCreated()->json('data.id');
        docPosted($id);
        agingInvoice('INV-2', '250000', '2026-05-20', null, '2026-05-15');

        expect(agingReport('2026-05-01')->json('data.totals.total'))->toBe('1000000.000000')
            ->and(agingReport('2026-05-12')->json('data.totals.total'))->toBe('400000.000000')
            ->and(agingReport('2026-05-31')->json('data.totals.total'))->toBe('650000.000000')
            ->and(agingReport('2026-03-01')->json('data.totals.total'))->toBe('0.000000');
    });

    it('does not count an allocation that was cancelled by the date', function () {
        $invoice = agingInvoice('INV-1', '1000000', '2026-04-14');
        docPosted(docEntry('receipt', ['party' => $this->customer, 'bank' => $this->cashAccount, 'total' => '600000', 'allocs' => [['open_item_id' => $invoice, 'amount' => '600000']]], ['accounting_date' => '2026-05-10'])->assertCreated()->json('data.id'));

        $this->actingAs($this->preparer)->postJson("{$this->url}/allocations/".DB::table('sl_allocations')->value('id').'/cancel', ['reason' => 'Salah faktur'])->assertOk();
        $cancelled = (string) DB::table('sl_allocations')->value('cancelled_on');

        // The payment stands as an advance again, which lowers the total; the invoice is what shows the allocation.
        $remaining = fn (string $asOf): string => collect(agingReport($asOf)->json('data.parties.0.items'))->firstWhere('document_number', 'INV-1')['remaining_amount'];

        expect($remaining(substr($cancelled, 0, 10)))->toBe('1000000.000000')
            ->and($remaining('2026-05-12'))->toBe('400000.000000');
    });

    it('leaves out a document that was reversed by the date', function () {
        $invoice = agingInvoice('INV-1', '1000000', '2026-04-14');
        reversePosted(journalOfDocument($invoice));

        expect(agingReport('2026-03-18')->json('data.totals.total'))->toBe('1000000.000000')
            ->and(agingReport('2026-03-20')->json('data.totals.total'))->toBe('0.000000');
    });
});

describe('the policy (SL-AC-04)', function () {
    it('reads the defaults at version 0 and changes the buckets without changing any amount', function () {
        agingInvoice('INV-1', '1000000', '2026-04-30');
        $before = DB::table('sl_open_items')->get()->map(fn ($row) => (array) $row)->all();

        $this->actingAs($this->viewer)->getJson("{$this->url}/aging-policy")->assertOk()->assertJsonPath('data', ['basis' => 'due_date', 'buckets' => [30, 60, 90], 'lock_version' => 0]);

        $this->actingAs($this->admin)->putJson("{$this->url}/aging-policy", ['lock_version' => 0, 'basis' => 'due_date', 'buckets' => [7, 14]])
            ->assertOk()->assertJsonPath('data.buckets', [7, 14])->assertJsonPath('data.lock_version', 1);

        $report = agingReport('2026-05-31')->assertOk()->json('data');

        expect(array_column($report['parameters']['buckets'], 'key'))->toBe(['current', '1-7', '8-14', 'over_14'])
            ->and($report['totals']['over_14'])->toBe('1000000.000000')
            ->and(DB::table('sl_open_items')->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
            ->and(AuditEntry::where('event', 'aging_policy.updated')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('is changed by whoever may, with the version it was read at, and with buckets that ascend', function () {
        $this->actingAs($this->viewer)->putJson("{$this->url}/aging-policy", ['lock_version' => 0, 'basis' => 'due_date', 'buckets' => [30]])->assertForbidden();
        $this->actingAs($this->admin)->putJson("{$this->url}/aging-policy", ['lock_version' => 0, 'basis' => 'due_date', 'buckets' => [60, 30]])->assertUnprocessable()->assertJsonValidationErrors(['buckets']);
        $this->actingAs($this->admin)->putJson("{$this->url}/aging-policy", ['lock_version' => 0, 'basis' => 'age', 'buckets' => [30]])->assertUnprocessable()->assertJsonValidationErrors(['basis']);

        $this->actingAs($this->admin)->putJson("{$this->url}/aging-policy", ['lock_version' => 0, 'basis' => 'document_date', 'buckets' => [30]])->assertOk();
        $this->actingAs($this->admin)->putJson("{$this->url}/aging-policy", ['lock_version' => 0, 'basis' => 'due_date', 'buckets' => [30]])->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->actingAs($this->admin)->putJson("{$this->url}/aging-policy", ['lock_version' => 1, 'basis' => 'due_date', 'buckets' => [30, 45]])->assertOk()->assertJsonPath('data.lock_version', 2);

        expect(agingReport('2026-05-31')->json('data.parameters.basis'))->toBe('due_date');
    });
});

describe('a foreign currency', function () {
    it('is shown in the currency of the document and in the functional one, with totals per currency', function () {
        mapFxRoles();
        docPosted(docEntry('usd_sale', ['party' => $this->customer, 'number' => 'INV-USD', 'due' => '2026-04-30', 'total' => '1000'], ['currency' => 'USD', 'exchange_rate' => '15000'])->assertCreated()->json('data.id'));
        agingInvoice('INV-1', '500000', '2026-04-30');

        $report = agingReport('2026-05-31')->assertOk()->json('data');
        $usd = collect($report['parties'][0]['items'])->firstWhere('document_number', 'INV-USD');

        expect($usd)->toMatchArray(['currency' => 'USD', 'remaining_amount' => '1000.000000', 'remaining_functional' => '15000000.000000'])
            ->and($report['totals']['total'])->toBe('15500000.000000')
            ->and(collect($report['currency_totals'])->keyBy('currency')->map(fn ($row) => $row['total'])->all())->toBe(['IDR' => '500000.000000', 'USD' => '1000.000000']);
    });
});

describe('the report as a download and its access', function () {
    it('downloads the same report with Accept: text/csv, and needs the permission', function () {
        agingInvoice('INV-1', '1000000', '2026-04-30');

        $response = $this->actingAs($this->viewer)->get("{$this->url}/reports/aging?as_of=2026-05-31&control_role=receivables_control", ['Accept' => 'text/csv'])->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('text/csv')
            ->and($response->streamedContent())->toContain('INV-1')->toContain('1000000.000000');

        $this->actingAs(User::factory()->create())->getJson("{$this->url}/reports/aging?as_of=2026-05-31&control_role=receivables_control")->assertForbidden();
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/aging?control_role=receivables_control")->assertUnprocessable();
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/aging?as_of=2026-05-31")->assertUnprocessable();
    });
});

describe('the balance of each party (SL-FR-08)', function () {
    function partyBalances(string $asOf, ?User $as = null)
    {
        return test()->actingAs($as ?? test()->viewer)->getJson(test()->url.'/reports/party-balances?as_of='.$asOf);
    }

    it('gives what is owed to the company and what it owes per party, as the aging totals do', function () {
        $supplier = docParty(['name' => 'UD Pemasok', 'is_customer' => false, 'is_supplier' => true]);
        docType('credit_purchase', docInvoiceBody([
            'posting_rules' => [
                ['side' => 'credit', 'account' => docControl()],
                ['side' => 'debit', 'account' => docFixed($this->expense)],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control'],
        ]));
        docPosted(docEntry('credit_purchase', ['party' => $supplier, 'number' => 'BILL-1', 'due' => '2026-04-30', 'total' => '800000'])->assertCreated()->json('data.id'));
        agingInvoice('INV-1', '1000000', '2026-04-30');
        agingInvoice('INV-2', '200000', '2026-04-30', $this->other);

        $balances = partyBalances('2026-05-31')->assertOk()->json('data');
        $byParty = collect($balances['parties'])->keyBy('party_id');

        expect($balances['as_of'])->toBe('2026-05-31')
            ->and($balances['functional_currency'])->toBe('IDR')
            ->and($byParty)->toHaveCount(3)
            ->and($byParty[$this->customer])->toMatchArray(['receivable' => '1000000.000000', 'payable' => '0.000000'])
            ->and($byParty[$this->other])->toMatchArray(['receivable' => '200000.000000', 'payable' => '0.000000'])
            ->and($byParty[$supplier])->toMatchArray(['receivable' => '0.000000', 'payable' => '800000.000000'])
            ->and($byParty[$this->customer]['receivable'])->toBe(agingReport('2026-05-31', ['party_id' => $this->customer])->json('data.totals.total'));
    });

    it('reads a past date and leaves out a party that has been settled by then', function () {
        $invoice = agingInvoice('INV-1', '1000000', '2026-04-14');
        docPosted(docEntry('receipt', ['party' => $this->customer, 'bank' => $this->cashAccount, 'total' => '1000000', 'allocs' => [['open_item_id' => $invoice, 'amount' => '1000000']]], ['accounting_date' => '2026-05-10'])->assertCreated()->json('data.id'));

        expect(collect(partyBalances('2026-05-01')->json('data.parties'))->pluck('receivable', 'party_id')->all())->toBe([$this->customer => '1000000.000000'])
            ->and(partyBalances('2026-05-31')->json('data.parties'))->toBe([]);
    });

    it('needs a date and the permission to read reports', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/party-balances")->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson("{$this->url}/reports/party-balances?as_of=2026-05-31")->assertForbidden();
    });
});
