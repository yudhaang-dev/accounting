<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;

// A loan: a document that raises a balance and moves cash at once, such as the advance of an employee (SL-FR-04,
// SL-FR-11, SL-AC-10). The helpers to build types and entries are those of DocumentTest and AllocationTest.

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

    foreach ([$this->receivables, $this->payables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    foreach (['receivables_control' => $this->receivables, 'payables_control' => $this->payables] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $this->employee = docParty(['name' => 'Siti Rahma']);
    $this->lender = docParty(['name' => 'Bank Mitra', 'roles' => ['supplier']]);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');
});

function docLoan(string $total, array $values = [], ?string $party = null)
{
    return docEntry('employee_advance', array_merge(['party' => $party ?? test()->employee, 'bank' => test()->cashAccount, 'total' => $total], $values));
}

describe('a loan given (SL-FR-04, SL-FR-11)', function () {
    beforeEach(function () {
        docType('employee_advance', docLoanBody())->assertCreated();
    });

    it('raises the receivable of the party and records the cash that went out', function () {
        $id = docLoan('2000000', ['number' => 'KSB-001', 'due' => '2026-04-30'])->assertCreated()->json('data.id');
        $posted = docPosted($id);

        $document = DB::table('sl_documents')->first();
        $item = DB::table('sl_open_items')->first();
        $cash = DB::table('sl_cash_transactions')->first();
        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->orderBy('line_number')->get();

        expect($document)->toMatchArray(['kind' => 'loan', 'control_role' => 'receivables_control', 'party_id' => $this->employee, 'cash_account_id' => $this->cashAccount, 'document_number' => 'KSB-001', 'amount' => '2000000.000000'])
            ->and($item)->toMatchArray(['kind' => 'loan', 'side' => 'debit', 'status' => 'open', 'original_amount' => '2000000.000000', 'remaining_amount' => '2000000.000000'])
            ->and($cash)->toMatchArray(['cash_account_id' => $this->cashAccount, 'direction' => 'out', 'amount' => '2000000.000000', 'reference' => 'KSB-001', 'document_id' => $document->id])
            ->and($lines->pluck('account_id')->all())->toBe([$this->receivables, $this->bank])
            ->and((float) $lines[0]->debit)->toBe(2000000.0)
            ->and((float) $lines[1]->credit)->toBe(2000000.0)
            ->and(DB::table('gl_journals')->where('id', $posted['journal_id'])->value('source'))->toBe('subledger')
            ->and(AuditEntry::where('event', 'document.recorded')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('is listed with the documents and open items of its kind', function () {
        docPosted(docLoan('2000000', ['number' => 'KSB-001'])->assertCreated()->json('data.id'));

        $documents = $this->actingAs($this->viewer)->getJson("{$this->url}/documents?filter[kind]=loan")->assertOk()->assertJsonCount(1, 'data')->json('data');
        $items = $this->actingAs($this->viewer)->getJson("{$this->url}/open-items?filter[kind]=loan&filter[status]=open")->assertOk()->assertJsonCount(1, 'data')->json('data');

        expect($documents[0])->toMatchArray(['kind' => 'loan', 'document_number' => 'KSB-001'])
            ->and($items[0])->toMatchArray(['kind' => 'loan', 'side' => 'debit', 'remaining_amount' => '2000000.000000']);

        $this->actingAs($this->viewer)->getJson("{$this->url}/documents?filter[kind]=invoice")->assertOk()->assertJsonCount(0, 'data');
    });

    it('moves the cash only to the account the entry names, in the currency of the account', function () {
        $petty = docAccountId('1111');
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$petty}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$petty}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
        $pettyCash = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", ['name' => 'Kas Kecil', 'kind' => 'cash', 'currency' => 'IDR', 'account_id' => $petty])->assertCreated()->json('data.id');

        docPosted(docLoan('500000', ['bank' => $pettyCash])->assertCreated()->json('data.id'));

        expect(DB::table('sl_cash_transactions')->first())->toMatchArray(['cash_account_id' => $pettyCash, 'direction' => 'out', 'amount' => '500000.000000'])
            ->and(DB::table('gl_journal_lines')->where('account_id', $petty)->count())->toBe(1)
            ->and(DB::table('gl_journal_lines')->where('account_id', $this->bank)->count())->toBe(0);
    });

    it('refuses the inactive party, the cash account and the due date that do not fit, on their fields', function () {
        $inactive = docParty(['name' => 'PT Tidur']);
        $this->actingAs($this->admin)->postJson("{$this->url}/parties/{$inactive}/deactivate")->assertOk();

        docAct($this->preparer, docLoan('1000', [], $inactive)->assertCreated()->json('data.id'), 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.party']);
        docAct($this->preparer, docLoan('1000', ['due' => '2026-03-01'])->assertCreated()->json('data.id'), 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.due']);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$this->cashAccount}/deactivate")->assertOk();
        docAct($this->preparer, docLoan('1000')->assertCreated()->json('data.id'), 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.bank']);

        expect(DB::table('sl_documents')->count())->toBe(0)->and(DB::table('sl_cash_transactions')->count())->toBe(0);
    });

    it('is settled by the payments that name it, and the aging shows what is left of it', function () {
        docType('receipt', docSettlingBody())->assertCreated();
        docPosted(docLoan('2000000', ['number' => 'KSB-001', 'due' => '2026-04-30'])->assertCreated()->json('data.id'));
        $loan = (string) DB::table('sl_open_items')->where('kind', 'loan')->value('id');

        docPosted(docReceipt('500000', [['open_item_id' => $loan, 'amount' => '500000']], $this->employee)->assertCreated()->json('data.id'));

        expect(docItem($loan))->toMatchArray(['remaining_amount' => '1500000.000000', 'status' => 'open'])
            ->and(DB::table('sl_allocations')->count())->toBe(1);

        $report = $this->actingAs($this->viewer)->getJson("{$this->url}/reports/aging?as_of=2026-05-31&control_role=receivables_control")->assertOk()->json('data');

        expect($report['totals']['total'])->toBe('1500000.000000')
            ->and(collect($report['parties'][0]['items'])->firstWhere('kind', 'loan'))->toMatchArray(['document_number' => 'KSB-001', 'remaining_amount' => '1500000.000000', 'bucket' => '31-60']);

        docPosted(docReceipt('1500000', [['open_item_id' => $loan, 'amount' => '1500000']], $this->employee)->assertCreated()->json('data.id'));

        expect(docItem($loan))->toMatchArray(['remaining_amount' => '0.000000', 'status' => 'settled']);
    });

    it('keeps the control account equal to its open items', function () {
        docPosted(docLoan('2000000')->assertCreated()->json('data.id'));

        $runs = collect($this->actingAs($this->admin)->postJson("{$this->url}/reconciliations", ['as_of' => '2026-03-31'])->assertCreated()->json('data'))->keyBy('account_code');

        expect($runs['1121']['difference'])->toBe('0.000000')
            ->and($runs['1112']['difference'])->toBe('0.000000')
            ->and(functionalBalance($this->receivables))->toBe(2000000.0);
    });

    it('is reversed with its cash, and its open item no longer counts', function () {
        docPosted(docLoan('2000000')->assertCreated()->json('data.id'));
        $loan = (string) DB::table('sl_open_items')->where('kind', 'loan')->value('id');

        $done = reversePosted(journalOfDocument($loan));
        $lines = DB::table('gl_journal_lines')->where('journal_id', $done['journal_id'])->orderBy('line_number')->get();

        expect(docItem($loan))->toMatchArray(['status' => 'reversed', 'remaining_amount' => '0.000000'])
            ->and($lines->pluck('account_id')->all())->toBe([$this->receivables, $this->bank])
            ->and((float) $lines[0]->credit)->toBe(2000000.0)
            ->and((float) $lines[1]->debit)->toBe(2000000.0)
            ->and(functionalBalance($this->receivables))->toBe(0.0)
            ->and(functionalBalance($this->bank))->toBe(0.0);
    });

    it('is not reversed while a payment still settles it', function () {
        docType('receipt', docSettlingBody())->assertCreated();
        docPosted(docLoan('2000000')->assertCreated()->json('data.id'));
        $loan = (string) DB::table('sl_open_items')->where('kind', 'loan')->value('id');
        docPosted(docReceipt('500000', [['open_item_id' => $loan, 'amount' => '500000']], $this->employee)->assertCreated()->json('data.id'));

        reverseRequest(journalOfDocument($loan))->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });
});

describe('a loan received (SL-FR-04)', function () {
    it('raises the payable of the party and records the cash that came in', function () {
        docType('bank_loan', docLoanBody([
            'fields' => [0 => ['label' => 'Pemberi pinjaman']],
            'posting_rules' => [
                ['side' => 'credit', 'account' => docControl()],
                ['side' => 'debit', 'account' => ['source' => 'cash_account', 'field' => 'bank']],
            ],
            'subledger_route' => ['control_role' => 'payables_control'],
        ]))->assertCreated();

        docPosted(docEntry('bank_loan', ['party' => $this->lender, 'bank' => $this->cashAccount, 'total' => '10000000', 'number' => 'PNJ-1'])->assertCreated()->json('data.id'));

        expect(DB::table('sl_open_items')->first())->toMatchArray(['kind' => 'loan', 'control_role' => 'payables_control', 'side' => 'credit', 'remaining_amount' => '10000000.000000'])
            ->and(DB::table('sl_cash_transactions')->first())->toMatchArray(['direction' => 'in', 'amount' => '10000000.000000'])
            ->and(functionalBalance($this->payables))->toBe(-10000000.0)
            ->and(functionalBalance($this->bank))->toBe(10000000.0);
    });
});

describe('the route of a loan (TE-FR-10)', function () {
    beforeEach(function () {
        $this->typeId = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'routed', 'label' => 'Routed'])->assertCreated()->json('data.id');
        $this->draft = fn (array $body) => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", $body);
    });

    it('is accepted with a rule on each of the control and the cash account, on opposite sides', function () {
        $versions = ($this->draft)(docLoanBody())->assertCreated()->json('data.versions');

        expect(collect($versions)->last()['subledger_route'])->toMatchArray(['kind' => 'loan', 'control_role' => 'receivables_control', 'cash_account_field' => 'bank', 'allocations_field' => null]);
    });

    it('needs the cash account field and a rule on the cash account', function () {
        ($this->draft)(docLoanBody(['subledger_route' => ['cash_account_field' => null]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.cash_account_field']);
        ($this->draft)(docLoanBody(['posting_rules' => [1 => ['account' => docFixed($this->sales)]]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route']);
    });

    it('raises the receivable, so it debits the control account and credits the cash account', function () {
        ($this->draft)(docLoanBody(['posting_rules' => [0 => ['side' => 'credit']]]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.side']);
        ($this->draft)(docLoanBody(['posting_rules' => [1 => ['side' => 'debit']]]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.1.side']);
    });

    it('does not settle anything by itself, so it takes no allocations field', function () {
        ($this->draft)(docLoanBody([
            'fields' => [5 => ['name' => 'allocs', 'type' => 'allocations', 'label' => 'Alokasi', 'required' => false]],
            'subledger_route' => ['allocations_field' => 'allocs'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.allocations_field']);
    });
});

describe('the database', function () {
    it('keeps a loan to a cash account, as it does a payment', function () {
        docType('employee_advance', docLoanBody())->assertCreated();
        docPosted(docLoan('1000')->assertCreated()->json('data.id'));

        expect(fn () => DB::transaction(fn () => DB::table('sl_documents')->insert([
            'id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'company_id' => $this->companyId, 'kind' => 'loan', 'cash_account_id' => null,
        ])))->toThrow(Exception::class);
    });
});
