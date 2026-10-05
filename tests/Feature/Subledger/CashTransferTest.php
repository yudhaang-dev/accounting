<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Transfers between cash accounts in different currencies (SL-FR-03, SL-FR-11, TE-FR-05, TE-FR-06, TE-AC-10).
// The helpers to build types and entries are those of DocumentTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->idrAccount = docAccountId('1112');
    $this->usdAccount = docAccountId('1115');
    $this->gain = docAccountId('4130');
    $this->loss = docAccountId('6220');

    foreach ([$this->idrAccount, $this->usdAccount] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'spot', 'label' => 'Spot'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/exchange-rates", ['rate_type' => 'spot', 'currency' => 'USD', 'effective_date' => '2026-03-01', 'rate' => '16000.00'])->assertCreated();

    foreach (['fx_realized_gain' => $this->gain, 'fx_realized_loss' => $this->loss] as $role => $account) {
        $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/{$role}", ['lock_version' => 0, 'account_id' => $account])->assertOk();
    }

    $cash = fn (string $name, string $currency, string $account) => $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => $name, 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => $name, 'currency' => $currency, 'account_id' => $account,
    ])->assertCreated()->json('data.id');
    $this->idrBank = $cash('IDR-001', 'IDR', $this->idrAccount);
    $this->usdBank = $cash('USD-001', 'USD', $this->usdAccount);
});

function transferBody(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'structured',
        'rate_type_key' => 'spot',
        'fields' => [
            ['name' => 'from', 'type' => 'cash_account', 'label' => 'Dari'],
            ['name' => 'to', 'type' => 'cash_account', 'label' => 'Ke'],
            ['name' => 'amount_out', 'type' => 'amount', 'label' => 'Dikeluarkan'],
            ['name' => 'amount_in', 'type' => 'amount', 'label' => 'Diterima'],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'amount_in', 'account' => ['source' => 'cash_account', 'field' => 'to'], 'apply_dimensions' => false, 'reuses_field' => false],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'amount_out', 'account' => ['source' => 'cash_account', 'field' => 'from'], 'apply_dimensions' => false, 'reuses_field' => false],
            ['type' => 'fx_difference', 'apply_dimensions' => false],
        ],
        'subledger_route' => ['kind' => 'transfer', 'cash_account_field' => 'from', 'counterpart_cash_account_field' => 'to'],
    ], $overrides);
}

function transferEntry(array $values, array $overrides = [])
{
    return docEntry('transfer', $values, $overrides);
}

describe('a transfer from a USD account to an IDR account (TE-AC-10)', function () {
    beforeEach(function () {
        docType('transfer', transferBody())->assertCreated();
    });

    it('posts in balance in the functional currency and carries the difference to the exchange accounts', function () {
        $id = transferEntry(['from' => $this->usdBank, 'to' => $this->idrBank, 'amount_out' => '100.00', 'amount_in' => '1590000'])->assertCreated()->json('data.id');
        $posted = docPosted($id);

        $lines = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->orderBy('line_number')->get();

        // 100 USD at 16,000 left, 1,590,000 IDR came in: 10,000 was lost on the way.
        expect($lines)->toHaveCount(3)
            ->and($lines[0])->toMatchArray(['account_id' => $this->idrAccount, 'currency' => 'IDR'])
            ->and($lines[1])->toMatchArray(['account_id' => $this->usdAccount, 'currency' => 'USD', 'transaction_amount' => '100.000000', 'exchange_rate' => '16000.000000000000'])
            ->and((float) $lines[1]->credit)->toBe(1600000.0)
            ->and($lines[2]->account_id)->toBe($this->loss)
            ->and((float) $lines[2]->debit)->toBe(10000.0)
            ->and(round((float) $lines->sum('debit'), 2))->toBe(round((float) $lines->sum('credit'), 2))
            ->and(DB::table('gl_journals')->where('id', $posted['journal_id'])->value('source'))->toBe('subledger');
    });

    it('records the cash that left and the cash that came in, each in its own currency, without a document', function () {
        $id = transferEntry(['from' => $this->usdBank, 'to' => $this->idrBank, 'amount_out' => '100.00', 'amount_in' => '1610000'])->assertCreated()->json('data.id');
        $posted = docPosted($id);

        $out = DB::table('sl_cash_transactions')->where('cash_account_id', $this->usdBank)->first();
        $in = DB::table('sl_cash_transactions')->where('cash_account_id', $this->idrBank)->first();

        expect($out)->toMatchArray(['direction' => 'out', 'amount' => '100.000000', 'currency' => 'USD', 'document_id' => null, 'journal_id' => $posted['journal_id'], 'cleared_on' => null])
            ->and($in)->toMatchArray(['direction' => 'in', 'amount' => '1610000.000000', 'currency' => 'IDR', 'document_id' => null, 'journal_id' => $posted['journal_id']])
            ->and($out->reference)->toBe($posted['journal_number'])
            ->and(DB::table('sl_documents')->count())->toBe(0)
            // 10,000 gained.
            ->and((float) DB::table('gl_journal_lines')->where('account_id', $this->gain)->sum('credit'))->toBe(10000.0);

        $this->actingAs($this->admin)->getJson("{$this->url}/cash-transactions?filter[cash_account_id]={$this->usdBank}")->assertOk()->assertJsonPath('data.0.journal_id', $posted['journal_id']);
    });

    it('records nothing twice when the entry is posted again', function () {
        $id = transferEntry(['from' => $this->usdBank, 'to' => $this->idrBank, 'amount_out' => '100.00', 'amount_in' => '1600000'])->assertCreated()->json('data.id');
        docPosted($id);
        docAct($this->approver, $id, 'post')->assertOk();

        expect(DB::table('sl_cash_transactions')->count())->toBe(2)->and(DB::table('gl_journals')->count())->toBe(1);
    });

    it('is refused with an error on the field of the account', function () {
        $submit = fn (array $values) => docAct($this->preparer, transferEntry($values + ['amount_out' => '100.00', 'amount_in' => '1600000'])->assertCreated()->json('data.id'), 'submit');

        $submit(['from' => $this->usdBank, 'to' => $this->usdBank])->assertUnprocessable()->assertJsonValidationErrors(['values.to']);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$this->idrBank}/deactivate")->assertOk();
        $submit(['from' => $this->usdBank, 'to' => $this->idrBank])->assertUnprocessable()->assertJsonValidationErrors(['values.to']);

        transferEntry(['from' => $this->usdBank, 'to' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'amount_out' => '1', 'amount_in' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['values.to']);
        expect(DB::table('sl_cash_transactions')->count())->toBe(0);
    });
});

describe('the route of a transfer', function () {
    it('refuses what a transfer cannot have', function () {
        $draft = fn (array $body) => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/".$this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 't'.uniqid(), 'label' => 'T'])->assertCreated()->json('data.id').'/versions', $body);

        $draft(transferBody(['subledger_route' => ['counterpart_cash_account_field' => 'from']]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.counterpart_cash_account_field']);
        $draft(transferBody(['subledger_route' => ['counterpart_cash_account_field' => null]]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.counterpart_cash_account_field']);
        $draft(transferBody(['subledger_route' => ['party_field' => 'from']]))->assertUnprocessable()->assertJsonValidationErrors(['subledger_route.party_field']);
        // The cash leaves the first account, so a debit to it is the wrong way round.
        $draft(transferBody(['posting_rules' => [1 => ['side' => 'debit']]]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.1.side']);
        $draft(transferBody(['posting_rules' => [0 => ['account' => ['field' => 'amount_in']]]]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account.field']);
        $draft(transferBody(['posting_rules' => [0 => ['account' => ['source' => 'control_account', 'party_field' => 'from']]]]))->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account.party_field']);
        $draft(transferBody())->assertCreated();
    });
});
