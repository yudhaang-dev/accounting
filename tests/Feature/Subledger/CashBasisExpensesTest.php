<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use Illuminate\Support\Facades\DB;

// Cash paid to suppliers attributed to the expense accounts of the bills (RP-FR-11). The helpers to build types
// and entries are those of DocumentTest and AllocationTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');
    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->payables = docAccountId('2110');
    $this->bank = docAccountId('1112');
    $this->rent = docAccountId('6220');
    $this->utilities = docAccountId('6230');
    $this->prepaid = docAccountId('1111');

    foreach ([$this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/payables_control", ['lock_version' => 0, 'account_id' => $this->payables])->assertOk();

    $this->supplier = docParty(['name' => 'UD Pemasok', 'is_customer' => false, 'is_supplier' => true]);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');

    // A bill of rent, utilities and a prepaid asset against the payable.
    docType('purchase_bill', [
        'mode' => 'structured',
        'fields' => [
            ['name' => 'party', 'type' => 'party', 'label' => 'Pemasok'],
            ['name' => 'number', 'type' => 'reference', 'label' => 'Nomor', 'required' => false],
            ['name' => 'rent', 'type' => 'amount', 'label' => 'Sewa'],
            ['name' => 'utilities', 'type' => 'amount', 'label' => 'Utilitas'],
            ['name' => 'prepaid', 'type' => 'amount', 'label' => 'Dibayar di muka'],
            ['name' => 'total', 'type' => 'amount', 'label' => 'Total'],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'rent', 'account' => docFixed($this->rent), 'apply_dimensions' => false],
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'utilities', 'account' => docFixed($this->utilities), 'apply_dimensions' => false],
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'prepaid', 'account' => docFixed($this->prepaid), 'apply_dimensions' => false],
        ],
        'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control', 'party_field' => 'party', 'document_number_field' => 'number', 'due_date_field' => null, 'cash_account_field' => null],
    ]);
    docType('supplier_payment', docSettlingBody([
        'posting_rules' => [
            0 => ['side' => 'credit', 'account' => ['source' => 'cash_account', 'field' => 'bank']],
            1 => ['side' => 'debit', 'account' => docControl()],
        ],
        'subledger_route' => ['control_role' => 'payables_control'],
    ]));
});

/** Posts a bill of 600 000 rent, 300 000 utilities and 100 000 prepaid, and returns its open item. */
function cashBasisBill(string $number = 'BILL-1', string $date = '2026-03-10'): string
{
    docPosted(docEntry('purchase_bill', ['party' => test()->supplier, 'number' => $number, 'rent' => '600000', 'utilities' => '300000', 'prepaid' => '100000', 'total' => '1000000'], ['accounting_date' => $date])->assertCreated()->json('data.id'));

    return (string) DB::table('sl_open_items')->where('document_number', $number)->value('id');
}

function cashBasisPay(string $total, array $allocations, string $date = '2026-03-20'): array
{
    return docPosted(docEntry('supplier_payment', array_filter(['party' => test()->supplier, 'bank' => test()->cashAccount, 'total' => $total, 'allocs' => $allocations ?: null]), ['accounting_date' => $date])->assertCreated()->json('data.id'));
}

function cashBasisReport(string $from, string $to, ?User $as = null)
{
    return test()->actingAs($as ?? test()->viewer)->getJson(test()->url.'/reports/cash-basis-expenses?'.http_build_query(['from' => $from, 'to' => $to]));
}

describe('cash paid attributed to the expense accounts (RP-FR-11)', function () {
    it('spreads a payment over the lines of the bill in proportion, apart from what is not an expense', function () {
        $bill = cashBasisBill();
        cashBasisPay('500000', [['open_item_id' => $bill, 'amount' => '500000']]);

        $report = cashBasisReport('2026-03-01', '2026-03-31')->assertOk()->json('data');

        expect($report['parameters'])->toBe(['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->and($report['functional_currency'])->toBe('IDR')
            ->and(array_column($report['accounts'], 'cash_paid', 'account_code'))->toBe(['6220' => '300000.000000', '6230' => '150000.000000'])
            ->and($report['other_accounts'])->toBe('50000.000000')
            ->and($report['unattributed'])->toBe('0.000000')
            ->and($report['total'])->toBe('500000.000000')
            ->and($report['accounts'][0])->toMatchArray(['account_id' => $this->rent, 'account_name' => 'Sewa Kantor']);
    });

    it('counts each payment on the date it settled the bill, and lets a bill be paid in parts', function () {
        $bill = cashBasisBill();
        cashBasisPay('400000', [['open_item_id' => $bill, 'amount' => '400000']], '2026-03-20');
        cashBasisPay('600000', [['open_item_id' => $bill, 'amount' => '600000']], '2026-04-05');

        $march = cashBasisReport('2026-03-01', '2026-03-31')->assertOk()->json('data');
        $april = cashBasisReport('2026-04-01', '2026-04-30')->assertOk()->json('data');
        $year = cashBasisReport('2026-01-01', '2026-12-31')->assertOk()->json('data');

        expect(array_column($march['accounts'], 'cash_paid', 'account_code'))->toBe(['6220' => '240000.000000', '6230' => '120000.000000'])
            ->and(array_column($april['accounts'], 'cash_paid', 'account_code'))->toBe(['6220' => '360000.000000', '6230' => '180000.000000'])
            ->and(array_column($year['accounts'], 'cash_paid', 'account_code'))->toBe(['6220' => '600000.000000', '6230' => '300000.000000'])
            ->and($year['total'])->toBe('1000000.000000');
    });

    it('leaves out a payment that settles no bill until it is applied, then counts it on that day', function () {
        $bill = cashBasisBill();
        cashBasisPay('1000000', []);

        expect(cashBasisReport('2026-03-01', '2026-03-31')->assertOk()->json('data.total'))->toBe('0.000000');

        $payment = (string) DB::table('sl_open_items')->where('kind', 'payment')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/allocations", ['open_item_id' => $payment, 'counterpart_open_item_id' => $bill, 'amount' => '1000000', 'allocation_date' => '2026-04-10'])->assertCreated();

        expect(cashBasisReport('2026-03-01', '2026-03-31')->json('data.total'))->toBe('0.000000')
            ->and(cashBasisReport('2026-04-01', '2026-04-30')->json('data.total'))->toBe('1000000.000000');
    });

    it('leaves out an allocation cancelled by the end of the range, and keeps one cancelled later', function () {
        $bill = cashBasisBill();
        cashBasisPay('500000', [['open_item_id' => $bill, 'amount' => '500000']]);
        $allocation = (string) DB::table('sl_allocations')->value('id');

        $this->actingAs($this->admin)->postJson("{$this->url}/allocations/{$allocation}/cancel", ['reason' => 'Salah alokasi'])->assertOk();
        $cancelledOn = substr((string) DB::table('sl_allocations')->value('cancelled_on'), 0, 10);

        expect(cashBasisReport('2026-03-01', '2026-03-31')->assertOk()->json('data.total'))->toBe('500000.000000')
            ->and(cashBasisReport('2026-03-01', $cancelledOn)->assertOk()->json('data.total'))->toBe('0.000000');
    });

    it('keeps the shares adding up to the cash when they do not divide evenly', function () {
        $bill = cashBasisBill();
        cashBasisPay('333333.33', [['open_item_id' => $bill, 'amount' => '333333.33']]);

        $report = cashBasisReport('2026-03-01', '2026-03-31')->assertOk()->json('data');
        $sum = array_sum(array_map('floatval', array_column($report['accounts'], 'cash_paid'))) + (float) $report['other_accounts'];

        expect($report['total'])->toBe('333333.330000')
            ->and(round($sum, 2))->toBe(333333.33);
    });

    it('downloads the same rows as CSV', function () {
        $bill = cashBasisBill();
        cashBasisPay('500000', [['open_item_id' => $bill, 'amount' => '500000']]);

        $response = $this->actingAs($this->viewer)->get("{$this->url}/reports/cash-basis-expenses?from=2026-03-01&to=2026-03-31", ['Accept' => 'text/csv'])->assertOk();

        expect($response->streamedContent())->toContain('6220,"Sewa Kantor"', '300000.000000', 'Total,,500000.000000');
    });

    it('asks for dates and a range that is not backwards', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/cash-basis-expenses")->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        cashBasisReport('2026-04-01', '2026-03-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    });

    it('refuses a user limited to some dimension values', function () {
        $member = memberWithRoles($this->companyId, 'accounting_admin');
        $type = collect($this->actingAs($this->admin)->getJson("{$this->url}/dimension-types")->json('data'))->firstWhere('key', 'department')['id'];
        $value = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type, 'code' => 'SALES', 'name' => 'Sales'])->assertCreated()->json('data.id');
        $membership = Membership::where('user_id', $member->id)->firstOrFail();
        $this->actingAs($this->admin)->patchJson("{$this->url}/memberships/{$membership->id}", ['lock_version' => $membership->lock_version, 'data_scope' => [$value]])->assertOk();

        cashBasisReport('2026-03-01', '2026-03-31', $member)->assertForbidden();
    });
});
