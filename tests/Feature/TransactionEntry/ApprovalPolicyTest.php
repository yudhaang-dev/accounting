<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

// Approval policy, stages and separation of duties (TE-FR-09, TE-AC-05).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->first = memberWithRoles($this->companyId, 'approver');
    $this->second = memberWithRoles($this->companyId, 'approver');
    $this->both = memberWithRoles($this->companyId, 'preparer', 'approver');

    $this->bank = apAccountId('1112');
    $this->sales = apAccountId('4110');

    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
});

function apAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function apPolicy(array $rules, bool $sod = false, int $version = 0)
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/approval-policy', ['lock_version' => $version, 'separation_of_duties' => $sod, 'rules' => $rules]);
}

function apRule(array $overrides = []): array
{
    return array_merge(['transaction_type_key' => null, 'source' => null, 'threshold' => null, 'stages' => ['entries.approve'], 'auto_post' => false], $overrides);
}

function apEntry(User $by, string $amount = '1500.00')
{
    return test()->actingAs($by)->postJson(test()->url.'/entries', [
        'transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'Penjualan',
        'lines' => [['account_id' => test()->bank, 'debit' => $amount], ['account_id' => test()->sales, 'credit' => $amount]],
    ])->assertCreated()->json('data.id');
}

function apAct(User $user, string $id, string $action)
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}");
}

describe('the policy', function () {
    it('starts as version 0 without rules and changes as new versions', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/approval-policy")
            ->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.rules', [])->assertJsonPath('data.separation_of_duties', false);

        apPolicy([apRule(['transaction_type_key' => 'general_journal', 'threshold' => '1000', 'stages' => ['entries.approve', 'entries.approve']])], true)
            ->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.rules.0.threshold', '1000')->assertJsonPath('data.rules.0.stages.1', 'entries.approve');

        apPolicy([], false, 0)->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        apPolicy([], false, 1)->assertOk()->assertJsonPath('data.version', 2);

        expect(DB::table('te_approval_policies')->where('company_id', $this->companyId)->count())->toBe(2);
    });

    it('refuses a transaction type or permission that does not exist, and a change by anyone but a policy manager', function () {
        apPolicy([apRule(['transaction_type_key' => 'nope'])])->assertUnprocessable()->assertJsonValidationErrors(['rules.0.transaction_type_key']);
        apPolicy([apRule(['stages' => ['entries.approve', 'not.a.permission']])])->assertUnprocessable()->assertJsonValidationErrors(['rules.0.stages.1']);
        apPolicy([apRule(['source' => 'carrier_pigeon'])])->assertUnprocessable()->assertJsonValidationErrors(['rules.0.source']);
        apPolicy([apRule(['transaction_type_key' => 'system.reversal'])])->assertOk();

        $this->actingAs($this->preparer)->getJson("{$this->url}/approval-policy")->assertForbidden();
        $this->actingAs($this->first)->putJson("{$this->url}/approval-policy", ['lock_version' => 1, 'separation_of_duties' => false, 'rules' => []])->assertForbidden();
    });
});

describe('stages', function () {
    it('keeps an entry above the threshold from posting until two different approvers approved, never its creator (TE-AC-05)', function () {
        apPolicy([apRule(['threshold' => '1000', 'stages' => ['entries.approve', 'entries.approve']])], true)->assertOk();

        $id = apEntry($this->both);
        apAct($this->both, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.approval.stages', ['entries.approve', 'entries.approve'])->assertJsonPath('data.approval.approved', 0);

        // The creator holds the permission but is the creator.
        apAct($this->both, $id, 'approve')->assertForbidden()->assertJsonPath('code', 'SEGREGATION_OF_DUTIES');

        apAct($this->first, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.approval.approved', 1);
        apAct($this->first, $id, 'post')->assertStatus(409);
        apAct($this->first, $id, 'approve')->assertForbidden()->assertJsonPath('code', 'SEGREGATION_OF_DUTIES');
        apAct($this->both, $id, 'approve')->assertForbidden();

        apAct($this->second, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approval.approved', 2);
        apAct($this->second, $id, 'post')->assertOk()->assertJsonPath('data.status', 'posted');

        $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$id}")
            ->assertJsonPath('data.decisions.1.action', 'approve')->assertJsonPath('data.decisions.2.action', 'approve');
    });

    it('asks only one approval of an entry at or below the threshold', function () {
        apPolicy([apRule(['threshold' => '1000', 'stages' => ['entries.approve', 'entries.approve']])], true)->assertOk();

        $id = apEntry($this->preparer, '1000.00');
        apAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.approval.stages', ['entries.approve']);
        apAct($this->first, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
    });

    it('asks the permission of the stage, not only the one of the route', function () {
        apPolicy([apRule(['stages' => ['policies.manage']])])->assertOk();

        $id = apEntry($this->preparer);
        apAct($this->preparer, $id, 'submit')->assertOk();

        apAct($this->first, $id, 'approve')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN')->assertJsonValidationErrors(['permission']);
        apAct($this->admin, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
    });

    it('takes the first rule that matches and ignores one for another type or source', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'other_type', 'label' => 'Lain'])->assertCreated();

        apPolicy([
            apRule(['transaction_type_key' => 'other_type', 'stages' => []]),
            apRule(['source' => 'integration', 'stages' => []]),
            apRule(['transaction_type_key' => 'general_journal', 'stages' => ['entries.approve', 'entries.approve']]),
            apRule(['stages' => []]),
        ])->assertOk();

        $id = apEntry($this->preparer);
        apAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonCount(2, 'data.approval.stages');
    });

    it('keeps the plan an entry got when it was submitted', function () {
        apPolicy([apRule(['stages' => ['entries.approve', 'entries.approve']])])->assertOk();

        $id = apEntry($this->preparer);
        apAct($this->preparer, $id, 'submit')->assertOk();

        apPolicy([apRule(['stages' => []])], false, 1)->assertOk();

        apAct($this->first, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'submitted');
        apAct($this->second, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
    });

    it('plans again when a rejected entry is revised and submitted', function () {
        apPolicy([apRule(['stages' => ['entries.approve', 'entries.approve']])])->assertOk();

        $id = apEntry($this->preparer);
        apAct($this->preparer, $id, 'submit')->assertOk();
        apAct($this->first, $id, 'approve')->assertOk();
        $this->actingAs($this->second)->postJson("{$this->url}/entries/{$id}/reject", ['reason' => 'salah'])->assertOk();
        apAct($this->preparer, $id, 'revise')->assertOk();
        apAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.approval.approved', 0);

        // The first approver of the earlier round starts the new one afresh.
        apAct($this->first, $id, 'approve')->assertOk()->assertJsonPath('data.approval.approved', 1);
    });
});

describe('automatic approval and posting', function () {
    it('approves automatically when the rule has no stages', function () {
        apPolicy([apRule(['stages' => []])])->assertOk();

        $id = apEntry($this->preparer);
        apAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.journal_id', null);

        $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.decisions.1.action', 'auto_approve');
        apAct($this->first, $id, 'post')->assertOk()->assertJsonPath('data.status', 'posted');
    });

    it('posts after the last approval when the rule says so', function () {
        apPolicy([apRule(['stages' => ['entries.approve', 'entries.approve'], 'auto_post' => true])])->assertOk();

        $id = apEntry($this->preparer);
        apAct($this->preparer, $id, 'submit')->assertOk();
        apAct($this->first, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'submitted');
        apAct($this->second, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.journal_number', fn ($number) => $number !== null);
    });

    it('approves and posts at submit when the rule has no stages and posts automatically', function () {
        apPolicy([apRule(['stages' => [], 'auto_post' => true])])->assertOk();

        $id = apEntry($this->preparer);
        apAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'posted');
    });

    it('does not post by itself when the ledger refuses, and leaves the entry as it was', function () {
        apPolicy([apRule(['stages' => [], 'auto_post' => true])])->assertOk();

        // No fiscal period covers this date, so the validation at submit already refuses it.
        $id = $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal', 'accounting_date' => '2030-03-15', 'description' => 'x',
            'lines' => [['account_id' => $this->bank, 'debit' => '1.00'], ['account_id' => $this->sales, 'credit' => '1.00']],
        ])->assertCreated()->json('data.id');

        apAct($this->preparer, $id, 'submit')->assertUnprocessable();
        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.approval', null);
    });
});
