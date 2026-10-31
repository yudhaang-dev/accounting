<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// The route `cash` of a Jenis Transaksi: cash without a party, recorded as a cash transaction (TE-FR-10, SL-FR-11, ADR-0016).
// The helpers to build types and entries are those of DocumentTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->bankAccount = docAccountId('1112');
    $this->expense = docAccountId('6220');
    $this->capital = docAccountId('3110');

    $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$this->bankAccount}")->json('data.lock_version');
    $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->bankAccount}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();

    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'Kas Kecil', 'kind' => 'cash', 'currency' => 'IDR', 'account_id' => $this->bankAccount,
    ])->assertCreated()->json('data.id');
});

/** Cash against a fixed account: the cash rule credits (cash out) by default and debits (cash in) when the side is changed. */
function cashRouteBody(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'structured',
        'fields' => [
            ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Rekening'],
            ['name' => 'receipt', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
            ['name' => 'amount', 'type' => 'amount', 'label' => 'Jumlah'],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'amount', 'account' => docFixed(test()->expense), 'apply_dimensions' => false, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'amount', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
        ],
        'subledger_route' => ['kind' => 'cash', 'cash_account_field' => 'bank', 'document_number_field' => 'receipt'],
    ], $overrides);
}

function cashRouteDraft(array $body)
{
    $typeId = test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types', ['key' => 'c'.uniqid(), 'label' => 'C'])->assertCreated()->json('data.id');

    return test()->actingAs(test()->admin)->postJson(test()->url."/transaction-types/{$typeId}/versions", $body);
}

describe('an entry of the route cash', function () {
    it('records the cash that goes out with the receipt as its reference, and no document', function () {
        docType('cash_expense', cashRouteBody())->assertCreated();

        $id = docEntry('cash_expense', ['bank' => $this->cashAccount, 'receipt' => 'KWT-001', 'amount' => '250000'])->assertCreated()->json('data.id');
        $posted = docPosted($id);

        expect(DB::table('sl_cash_transactions')->first())->toMatchArray([
            'cash_account_id' => $this->cashAccount, 'direction' => 'out', 'amount' => '250000.000000', 'currency' => 'IDR',
            'reference' => 'KWT-001', 'journal_id' => $posted['journal_id'], 'document_id' => null,
        ])
            ->and(DB::table('gl_journals')->where('id', $posted['journal_id'])->value('source'))->toBe('subledger')
            ->and(DB::table('sl_documents')->count())->toBe(0)
            ->and(DB::table('sl_open_items')->count())->toBe(0);
    });

    it('records cash that comes in when the cash rule debits, and takes the journal number as the reference without a receipt', function () {
        docType('cash_capital', cashRouteBody([
            'posting_rules' => [
                0 => ['side' => 'credit', 'account' => docFixed($this->capital)],
                1 => ['side' => 'debit'],
            ],
        ]))->assertCreated();

        $posted = docPosted(docEntry('cash_capital', ['bank' => $this->cashAccount, 'amount' => '5000000'])->assertCreated()->json('data.id'));

        expect(DB::table('sl_cash_transactions')->first())->toMatchArray(['direction' => 'in', 'amount' => '5000000.000000', 'reference' => $posted['journal_number']]);
    });

    it('records nothing twice when the entry is posted again', function () {
        docType('cash_expense', cashRouteBody())->assertCreated();
        $id = docEntry('cash_expense', ['bank' => $this->cashAccount, 'amount' => '100000'])->assertCreated()->json('data.id');
        docPosted($id);
        docAct($this->approver, $id, 'post')->assertOk();

        expect(DB::table('sl_cash_transactions')->count())->toBe(1)->and(DB::table('gl_journals')->count())->toBe(1);
    });

    it('is refused on submit with an error on the field of the cash account', function () {
        docType('cash_expense', cashRouteBody())->assertCreated();
        $id = docEntry('cash_expense', ['bank' => $this->cashAccount, 'amount' => '100000'])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$this->cashAccount}/deactivate")->assertOk();

        docAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.bank']);
        docEntry('cash_expense', ['bank' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['values.bank']);
        expect(DB::table('sl_cash_transactions')->count())->toBe(0);
    });

    it('is refused where an account of the journal does not take the source subledger', function () {
        docType('cash_expense', cashRouteBody())->assertCreated();
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$this->expense}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->expense}", ['lock_version' => $version, 'allowed_sources' => ['entry']])->assertOk();

        $id = docEntry('cash_expense', ['bank' => $this->cashAccount, 'amount' => '100000'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertUnprocessable();
        expect(DB::table('sl_cash_transactions')->count())->toBe(0);
    });

    it('is posted from a financial event that names the cash account', function () {
        docType('cash_expense', cashRouteBody())->assertCreated();
        $client = registerClient(['scopes' => ['financial-events:write'], 'transaction_type_keys' => ['cash_expense']]);

        $event = evSend('POST', "{$this->url}/financial-events", [
            'transaction_type' => ['key' => 'cash_expense'],
            'accounting_date' => '2026-03-15',
            'currency' => 'IDR',
            'amounts' => ['amount' => '75000'],
            'cash_account' => $this->cashAccount,
            'references' => ['source_transaction_id' => 'PETTY-1'],
        ], evToken($client))->assertCreated()->json('data');

        docAct($this->approver, $event['event_id'], 'approve')->assertOk();
        $posted = docAct($this->approver, $event['event_id'], 'post')->assertOk()->json('data');

        expect(DB::table('sl_cash_transactions')->first())->toMatchArray(['direction' => 'out', 'amount' => '75000.000000', 'reference' => $posted['journal_number'], 'journal_id' => $posted['journal_id']]);
    });
});

describe('the route cash', function () {
    it('is published when it has one cash account and one cash rule', function () {
        cashRouteDraft(cashRouteBody())->assertCreated();
        cashRouteDraft(cashRouteBody(['subledger_route' => ['document_number_field' => null]]))->assertCreated();
    });

    it('refuses what cash without a party cannot have', function () {
        $draft = fn (array $body) => cashRouteDraft($body);
        $party = ['name' => 'party', 'type' => 'party', 'label' => 'Pihak'];
        $control = ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'amount', 'account' => ['source' => 'control_account', 'party_field' => 'party'], 'apply_dimensions' => false, 'reuses_field' => true];

        $draft(cashRouteBody(['subledger_route' => ['party_field' => 'party'], 'fields' => [3 => $party]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.party_field']);
        $draft(cashRouteBody(['subledger_route' => ['counterpart_cash_account_field' => 'bank']]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.counterpart_cash_account_field']);
        $draft(cashRouteBody(['subledger_route' => ['control_role' => 'receivables_control']]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.control_role']);
        $draft(cashRouteBody(['subledger_route' => ['cash_account_field' => null]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.cash_account_field']);
        // The field of the cash account, and of the receipt, must have the type they stand for.
        $draft(cashRouteBody(['subledger_route' => ['cash_account_field' => 'amount']]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.cash_account_field']);
        $draft(cashRouteBody(['subledger_route' => ['document_number_field' => 'amount']]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.document_number_field']);
        // A rule to a control account, no cash rule, and two cash rules.
        $draft(cashRouteBody(['posting_rules' => [0 => $control], 'fields' => [3 => $party]]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account']);
        $draft(cashRouteBody(['posting_rules' => [1 => ['account' => docFixed($this->capital)]]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route']);
        $draft(cashRouteBody(['posting_rules' => [2 => ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'amount', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true]]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route']);
    });

    it('is refused for a version with free lines', function () {
        cashRouteDraft(['mode' => 'free_lines', 'fields' => [], 'subledger_route' => ['kind' => 'cash', 'cash_account_field' => 'bank']])->assertUnprocessable()->assertJsonValidationErrors(['subledger_route']);
    });
});
