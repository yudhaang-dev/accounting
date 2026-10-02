<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');
    // Holds the soft-closed permission as well, through the admin role.
    $this->corrector = memberWithRoles($this->companyId, 'approver', 'accounting_admin');
    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->bank = softAccountId($this->companyId, '1112');
    $this->sales = softAccountId($this->companyId, '4110');

    foreach (['general_journal' => 'Jurnal Umum', 'audit_adjustment' => 'Koreksi Audit'] as $key => $label) {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => $key, 'label' => $label])->assertCreated();
    }

    // March 2026 is the period the tests work in.
    $this->march = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-03')->value('id');
});

function softAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function periodAction(string $action, array $body = [], ?User $as = null, ?string $periodId = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/periods/'.($periodId ?? test()->march)."/{$action}", $body);
}

function setLedgerPolicy(array $keys, ?string $permission, int $version)
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/ledger-policy', [
        'lock_version' => $version,
        'soft_closed_transaction_type_keys' => $keys,
        'soft_closed_permission' => $permission,
        'source_override_permission' => null,
    ]);
}

/**
 * An entry that is approved and ready to post, while its period is still open. Entries of different
 * transaction types get the same default number in one month until M5-11, so tests that need two
 * types put them in different months.
 */
function approvedEntry(string $key = 'general_journal', string $date = '2026-03-15'): string
{
    $id = test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', [
        'transaction_type_key' => $key,
        'accounting_date' => $date,
        'description' => 'Koreksi',
        'lines' => [
            ['account_id' => test()->bank, 'debit' => '1000.00'],
            ['account_id' => test()->sales, 'credit' => '1000.00'],
        ],
    ])->assertCreated()->json('data.id');

    test()->actingAs(test()->preparer)->postJson(test()->url."/entries/{$id}/submit")->assertOk();
    test()->actingAs(test()->approver)->postJson(test()->url."/entries/{$id}/approve")->assertOk();

    return $id;
}

function postEntryAs(User $user, string $id)
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/post");
}

describe('period status (GL-FR-04)', function () {
    it('soft-closes, closes and reopens a period, auditing each change with its reason', function () {
        periodAction('soft-close', ['reason' => 'Waiting for audit'])->assertOk()->assertJsonPath('data.status', 'soft_closed');
        periodAction('close')->assertOk()->assertJsonPath('data.status', 'closed');
        periodAction('reopen', ['reason' => 'Late invoice'])->assertOk()->assertJsonPath('data.status', 'open');

        $events = AuditEntry::where('company_id', $this->companyId)->whereIn('event', ['period.soft_closed', 'period.closed', 'period.reopened'])->orderBy('id')->get();

        expect($events->pluck('event')->all())->toBe(['period.soft_closed', 'period.closed', 'period.reopened'])
            ->and($events[0]->properties['reason'])->toBe('Waiting for audit')
            ->and($events[1]->properties->has('reason'))->toBeFalse()
            ->and($events[2]->properties['reason'])->toBe('Late invoice')
            ->and($events[2]->attribute_changes['old'])->toBe(['status' => 'closed'])
            ->and($events[2]->attribute_changes['attributes'])->toBe(['status' => 'open']);
    });

    it('reopens a soft-closed period as well, and only with a reason', function () {
        periodAction('soft-close')->assertOk();

        periodAction('reopen')->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        periodAction('reopen', ['reason' => 'Mistake'])->assertOk()->assertJsonPath('data.status', 'open');
    });

    it('refuses transitions that do not apply, naming the statuses', function () {
        periodAction('reopen', ['reason' => 'x'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        periodAction('close')->assertOk();
        periodAction('close')->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        periodAction('soft-close')->assertConflict()
            ->assertJsonPath('code', 'INVALID_STATE_TRANSITION')
            ->assertJsonPath('errors.status.0', 'A period that is closed cannot become soft_closed.');
    });

    it('lists soft-closed periods by status', function () {
        periodAction('soft-close')->assertOk();

        $this->actingAs($this->viewer)->getJson("{$this->url}/periods?filter[status]=soft_closed")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->march);
    });

    it('needs periods.close to close and periods.reopen to reopen', function () {
        periodAction('soft-close', as: $this->viewer)->assertForbidden();
        periodAction('close', as: $this->approver)->assertForbidden();

        periodAction('close')->assertOk();
        periodAction('reopen', ['reason' => 'x'], $this->approver)->assertForbidden();
    });
});

describe('the ledger policy of soft-closed periods (GL-FR-04)', function () {
    it('starts at version 0 and accepts nothing in a soft-closed period', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/ledger-policy")
            ->assertOk()
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.lock_version', 0)
            ->assertJsonPath('data.soft_closed_transaction_type_keys', [])
            ->assertJsonPath('data.soft_closed_permission', null);
    });

    it('is replaced as a new version, audited, with optimistic locking', function () {
        setLedgerPolicy(['audit_adjustment', 'system.closing'], 'journals.post-soft-closed', 0)
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.soft_closed_transaction_type_keys', ['audit_adjustment', 'system.closing'])
            ->assertJsonPath('data.soft_closed_permission', 'journals.post-soft-closed');

        setLedgerPolicy([], null, 0)->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        setLedgerPolicy(['general_journal'], null, 1)->assertOk()->assertJsonPath('data.version', 2);

        expect(DB::table('gl_ledger_policies')->where('company_id', $this->companyId)->pluck('version')->all())->toBe([1, 2]);

        $audit = AuditEntry::where('event', 'ledger_policy.changed')->where('company_id', $this->companyId)->orderBy('id')->get();

        expect($audit)->toHaveCount(2)
            ->and($audit[1]->attribute_changes['old']['soft_closed_transaction_type_keys'])->toBe(['audit_adjustment', 'system.closing'])
            ->and($audit[1]->attribute_changes['attributes']['soft_closed_transaction_type_keys'])->toBe(['general_journal']);
    });

    it('refuses a transaction type key or permission that does not exist', function () {
        setLedgerPolicy(['general_journal', 'typo_key'], null, 0)->assertUnprocessable()->assertJsonValidationErrors(['soft_closed_transaction_type_keys.1']);
        setLedgerPolicy([], 'journals.post-soft-closd', 0)->assertUnprocessable()->assertJsonValidationErrors(['soft_closed_permission']);
        setLedgerPolicy(['Bad Key'], null, 0)->assertUnprocessable()->assertJsonValidationErrors(['soft_closed_transaction_type_keys.0']);

        expect(DB::table('gl_ledger_policies')->count())->toBe(0);
    });

    it('is another company\'s own: keys of one company mean nothing in another', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->putJson("/api/v1/companies/{$otherCompany}/ledger-policy", [
            'lock_version' => 0, 'soft_closed_transaction_type_keys' => ['general_journal'], 'soft_closed_permission' => null, 'source_override_permission' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['soft_closed_transaction_type_keys.0']);
    });

    it('needs policies.manage', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/ledger-policy")->assertForbidden();
        $this->actingAs($this->viewer)->putJson("{$this->url}/ledger-policy", [
            'lock_version' => 0, 'soft_closed_transaction_type_keys' => [], 'soft_closed_permission' => null, 'source_override_permission' => null,
        ])->assertForbidden();
    });
});

describe('posting into a soft-closed period (GL-AC-04)', function () {
    it('refuses every posting while the policy names nothing', function () {
        $entry = approvedEntry();
        periodAction('soft-close')->assertOk();

        postEntryAs($this->corrector, $entry)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PERIOD_NOT_OPEN')
            ->assertJsonPath('errors.accounting_date.0', 'Period 2026-03 is soft-closed and only accepts the postings named by the ledger policy.');

        expect(DB::table('gl_journals')->count())->toBe(0);
    });

    it('accepts the named key from a user who holds the named permission, and only then', function () {
        setLedgerPolicy(['audit_adjustment'], 'journals.post-soft-closed', 0)->assertOk();

        $adjustment = approvedEntry('audit_adjustment');
        $ordinary = approvedEntry('general_journal', '2026-04-15');
        periodAction('soft-close')->assertOk();
        periodAction('soft-close', periodId: (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-04')->value('id'))->assertOk();

        // Right key, but the approver role does not hold the permission.
        postEntryAs($this->approver, $adjustment)->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
        // Holds the permission, but the key is not on the policy.
        postEntryAs($this->corrector, $ordinary)->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');

        postEntryAs($this->corrector, $adjustment)->assertOk()->assertJsonPath('data.status', 'posted');

        expect(DB::table('gl_journals')->where('company_id', $this->companyId)->pluck('transaction_type_key')->all())->toBe(['audit_adjustment']);
    });

    it('lets the key alone decide when the policy names no permission', function () {
        setLedgerPolicy(['audit_adjustment'], null, 0)->assertOk();

        $adjustment = approvedEntry('audit_adjustment');
        periodAction('soft-close')->assertOk();

        postEntryAs($this->approver, $adjustment)->assertOk()->assertJsonPath('data.status', 'posted');
    });

    it('never accepts a posting into a closed period, whatever the policy says', function () {
        setLedgerPolicy(['audit_adjustment'], null, 0)->assertOk();

        $adjustment = approvedEntry('audit_adjustment');
        periodAction('close')->assertOk();

        postEntryAs($this->corrector, $adjustment)->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
    });

    it('reopening restores ordinary posting', function () {
        $entry = approvedEntry();
        periodAction('soft-close')->assertOk();
        postEntryAs($this->corrector, $entry)->assertUnprocessable();

        periodAction('reopen', ['reason' => 'Corrections finished'])->assertOk();

        postEntryAs($this->approver, $entry)->assertOk()->assertJsonPath('data.status', 'posted');
    });
});
