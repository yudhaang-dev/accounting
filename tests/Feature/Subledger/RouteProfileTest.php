<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// A route that names its own posting profile (SL-FR-02, TE-FR-10, ADR-0015): the same party raises balances on
// the control account of the route, not of its profile. The helpers are those of DocumentTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->receivables = docAccountId('1121');
    $this->relatedReceivables = docAccountId('1123');
    $this->bank = docAccountId('1112');
    $this->sales = docAccountId('4110');

    foreach ([$this->receivables, $this->relatedReceivables, $this->bank] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }

    foreach (['Third parties' => $this->receivables, 'Related parties' => $this->relatedReceivables] as $name => $account) {
        $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => $name])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $account])->assertOk();
        $profiles[$name] = $profile;
    }

    $this->thirdProfile = $profiles['Third parties'];
    $this->relatedProfile = $profiles['Related parties'];
    $this->unmappedProfile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Empty'])->assertCreated()->json('data.id');

    // A person whose own profile is that of third parties, such as an employee who is also a customer.
    $this->person = docParty(['name' => 'Siti Rahma', 'posting_profile_id' => $this->thirdProfile]);
    $this->cashAccount = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
        'name' => 'BCA', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '999', 'currency' => 'IDR', 'account_id' => $this->bank,
    ])->assertCreated()->json('data.id');
});

function routeOn(string $profileId): array
{
    return ['subledger_route' => ['posting_profile_id' => $profileId]];
}

describe('publishing a route with a profile (TE-FR-10)', function () {
    it('refuses a profile that does not exist or does not map the role of the route', function () {
        docType('unknown_profile', docInvoiceBody(routeOn((string) str()->ulid())))
            ->assertStatus(422)->assertJsonValidationErrors(['subledger_route.posting_profile_id']);
        docType('unmapped_profile', docInvoiceBody(routeOn($this->unmappedProfile)))
            ->assertStatus(422)->assertJsonValidationErrors(['subledger_route.posting_profile_id']);
    });

    it('refuses a profile on a transfer between cash accounts, which has no control account', function () {
        docType('transfer_profile', [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'from', 'type' => 'cash_account', 'label' => 'Dari'],
                ['name' => 'to', 'type' => 'cash_account', 'label' => 'Ke'],
            ],
            'posting_rules' => [],
            'subledger_route' => ['kind' => 'transfer', 'cash_account_field' => 'from', 'counterpart_cash_account_field' => 'to', 'posting_profile_id' => $this->relatedProfile],
        ])->assertStatus(422)->assertJsonValidationErrors(['subledger_route.posting_profile_id']);
    });

    it('keeps the profile on the stored route and takes a route without one as before', function () {
        $with = docType('with_profile', docInvoiceBody(routeOn($this->relatedProfile)))->assertCreated()->json('data.versions');
        $without = docType('without_profile', docInvoiceBody())->assertCreated()->json('data.versions');

        expect(collect($with)->last()['subledger_route']['posting_profile_id'])->toBe($this->relatedProfile)
            ->and(collect($without)->last()['subledger_route']['posting_profile_id'])->toBeNull();
    });
});

describe('an entry of a route with a profile (SL-FR-02)', function () {
    beforeEach(function () {
        docType('loan_sale', docInvoiceBody(routeOn($this->relatedProfile)))->assertCreated();
        docType('credit_sale', docInvoiceBody())->assertCreated();
        docType('loan_receipt', docSettlingBody(routeOn($this->relatedProfile)))->assertCreated();
        docType('receipt', docSettlingBody())->assertCreated();
    });

    it('posts to the control account of the profile of the route, not of the party, and records the profile', function () {
        $id = docEntry('loan_sale', ['party' => $this->person, 'total' => '500000'])->assertCreated()->json('data.id');
        $posted = docPosted($id);
        $accounts = DB::table('gl_journal_lines')->where('journal_id', $posted['journal_id'])->pluck('account_id')->all();
        $own = docEntry('credit_sale', ['party' => $this->person, 'total' => '200000'])->assertCreated()->json('data.id');
        $ownPosted = docPosted($own);

        expect($accounts)->toContain($this->relatedReceivables)->not->toContain($this->receivables)
            ->and(DB::table('sl_documents')->where('journal_id', $posted['journal_id'])->value('posting_profile_id'))->toBe($this->relatedProfile)
            ->and(DB::table('sl_open_items')->where('document_id', DB::table('sl_documents')->where('journal_id', $posted['journal_id'])->value('id'))->value('posting_profile_id'))->toBe($this->relatedProfile)
            ->and(DB::table('gl_journal_lines')->where('journal_id', $ownPosted['journal_id'])->pluck('account_id')->all())->toContain($this->receivables)
            ->and(DB::table('sl_documents')->where('journal_id', $ownPosted['journal_id'])->value('posting_profile_id'))->toBeNull();
    });

    it('keeps the two balances of the same person apart and reconciled on each account', function () {
        docPosted(docEntry('loan_sale', ['party' => $this->person, 'total' => '500000'])->assertCreated()->json('data.id'));
        docPosted(docEntry('credit_sale', ['party' => $this->person, 'total' => '200000'])->assertCreated()->json('data.id'));

        $runs = collect($this->actingAs($this->admin)->postJson("{$this->url}/reconciliations", ['as_of' => '2026-12-31'])->assertCreated()->json('data'))->keyBy('account_code');

        expect($runs['1123'])->toMatchArray(['ledger_balance' => '500000.000000', 'detail_total' => '500000.000000', 'status' => 'balanced'])
            ->and($runs['1121'])->toMatchArray(['ledger_balance' => '200000.000000', 'detail_total' => '200000.000000', 'status' => 'balanced']);
    });

    it('lets a receipt of the same profile settle the balance and refuses one of the profile of the party', function () {
        $loan = docEntry('loan_sale', ['party' => $this->person, 'total' => '500000'])->assertCreated()->json('data.id');
        docPosted($loan);
        $item = (string) DB::table('sl_open_items')->orderByDesc('id')->value('id');

        $refused = docEntry('receipt', ['party' => $this->person, 'bank' => $this->cashAccount, 'total' => '100000', 'allocs' => [['open_item_id' => $item, 'amount' => '100000']]])->assertCreated()->json('data.id');
        docAct($this->preparer, $refused, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.allocs']);

        docPosted(docEntry('loan_receipt', ['party' => $this->person, 'bank' => $this->cashAccount, 'total' => '100000', 'allocs' => [['open_item_id' => $item, 'amount' => '100000']]])->assertCreated()->json('data.id'));

        expect((string) docItem($item)->remaining_amount)->toBe('400000.000000');
    });

    it('is refused with MAPPING_REQUIRED while the profile of the route has no mapping in force on the date', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$this->unmappedProfile}/accounts/receivables_control", [
            'lock_version' => 0, 'account_id' => $this->relatedReceivables, 'effective_date' => '2026-06-01',
        ])->assertOk();
        docType('later_sale', docInvoiceBody(routeOn($this->unmappedProfile)))->assertCreated();

        docEntry('later_sale', ['party' => $this->person, 'total' => '500000'])
            ->assertStatus(422)->assertJsonPath('code', 'MAPPING_REQUIRED');
    });
});
