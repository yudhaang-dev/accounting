<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Modules\TransactionEntry\Domain\VersionStatus;
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
    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->bank = entryAccountId($this->companyId, '1112');
    $this->sales = entryAccountId($this->companyId, '4110');

    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
});

function entryAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function entryPayload(array $overrides = []): array
{
    return array_merge([
        'transaction_type_key' => 'general_journal',
        'accounting_date' => '2026-03-15',
        'description' => 'Penjualan tunai',
        'lines' => [
            ['account_id' => test()->bank, 'debit' => '1000.00'],
            ['account_id' => test()->sales, 'credit' => '1000.00'],
        ],
    ], $overrides);
}

function createEntry(array $overrides = []): string
{
    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', entryPayload($overrides))->assertCreated()->json('data.id');
}

function entryAction(User $user, string $id, string $action, array $body = [])
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}", $body);
}

function postedEntry(): string
{
    $id = createEntry();
    entryAction(test()->preparer, $id, 'submit')->assertOk();
    entryAction(test()->approver, $id, 'approve')->assertOk();
    entryAction(test()->approver, $id, 'post')->assertOk();

    return $id;
}

describe('transaction types (TE-FR-01)', function () {
    it('lists the type with its published free_lines version', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/transaction-types")
            ->assertOk()
            ->assertJsonPath('data.0.key', 'general_journal')
            ->assertJsonPath('data.0.versions.0.mode', 'free_lines')
            ->assertJsonPath('data.0.versions.0.status', 'published');
    });

    it('refuses a duplicate key and a key that looks like a system key', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Lagi'])
            ->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'system.closing', 'label' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('key');
    });

    it('is managed with policies.manage only', function () {
        $this->actingAs($this->preparer)->postJson("{$this->url}/transaction-types", ['key' => 'adjustment', 'label' => 'Penyesuaian'])->assertForbidden();
    });
});

describe('entry lifecycle (TE-FR-07, TE-FR-10)', function () {
    it('creates a draft with a number and the latest published version', function () {
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", entryPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.transaction_type_version', 1)
            ->assertJsonPath('data.number', 'ENT/2026/03/000001')
            ->assertJsonPath('data.created_by', $this->preparer->id);
    });

    it('takes an entry from draft through submit, approve and post into one journal', function () {
        $id = createEntry();

        entryAction($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        entryAction($this->approver, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $posted = entryAction($this->approver, $id, 'post')->assertOk()->assertJsonPath('data.status', 'posted');

        $journal = DB::table('gl_journals')->where('entry_id', $id)->first();
        expect($journal)->not->toBeNull()
            ->and($journal->number)->toBe($posted->json('data.journal_number'))
            ->and($journal->id)->toBe($posted->json('data.journal_id'))
            ->and($journal->source)->toBe('entry')
            ->and($journal->transaction_type_key)->toBe('general_journal')
            ->and($journal->posted_by)->toBe($this->approver->id);
        expect(DB::table('gl_journal_lines')->where('journal_id', $journal->id)->count())->toBe(2);

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$id}")
            ->assertOk()
            ->assertJsonPath('data.decisions.0.action', 'submit')
            ->assertJsonPath('data.decisions.1.action', 'approve')
            ->assertJsonPath('data.decisions.2.action', 'post')
            ->assertJsonPath('data.decisions.1.actor_id', $this->approver->id);
    });

    it('returns the same journal when posting again', function () {
        $id = postedEntry();
        $first = $this->actingAs($this->approver)->getJson("{$this->url}/entries/{$id}")->json('data.journal_id');

        entryAction($this->approver, $id, 'post')->assertOk()->assertJsonPath('data.journal_id', $first);

        expect(DB::table('gl_journals')->count())->toBe(1);
    });

    it('only lets a draft be edited, and rejects a stale lock_version', function () {
        $id = createEntry();
        $body = entryPayload(['description' => 'Diubah']) + ['lock_version' => 1];
        unset($body['transaction_type_key']);

        $this->actingAs($this->preparer)->patchJson("{$this->url}/entries/{$id}", $body)
            ->assertOk()->assertJsonPath('data.description', 'Diubah')->assertJsonPath('data.lock_version', 2);
        $this->actingAs($this->preparer)->patchJson("{$this->url}/entries/{$id}", $body)
            ->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');

        entryAction($this->preparer, $id, 'submit')->assertOk();
        $body['lock_version'] = 3;
        $this->actingAs($this->preparer)->patchJson("{$this->url}/entries/{$id}", $body)
            ->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('requires a reason to reject, then lets a manual entry be revised and resubmitted', function () {
        $id = createEntry();
        entryAction($this->preparer, $id, 'submit')->assertOk();

        entryAction($this->approver, $id, 'reject')->assertUnprocessable()->assertJsonValidationErrors('reason');
        entryAction($this->approver, $id, 'reject', ['reason' => 'Akun salah'])->assertOk()->assertJsonPath('data.status', 'rejected');

        entryAction($this->preparer, $id, 'revise')->assertOk()->assertJsonPath('data.status', 'draft');
        entryAction($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$id}")
            ->assertJsonPath('data.decisions.1.action', 'reject')
            ->assertJsonPath('data.decisions.1.reason', 'Akun salah');
    });

    it('cancels an unused draft without deleting it', function () {
        $id = createEntry();

        entryAction($this->preparer, $id, 'cancel', ['reason' => 'Tidak dipakai'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$id}")->assertOk();
        entryAction($this->preparer, $id, 'submit')->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('refuses actions that do not apply to the current status', function () {
        $id = createEntry();

        entryAction($this->approver, $id, 'approve')->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        entryAction($this->approver, $id, 'post')->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $posted = postedEntry();
        entryAction($this->preparer, $posted, 'cancel')->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('filters and paginates the list', function () {
        $draft = createEntry();
        postedEntry();

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries?filter[status]=draft")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft);
        $this->actingAs($this->viewer)->getJson("{$this->url}/entries?filter[status]=bogus")->assertUnprocessable();
        $this->actingAs($this->viewer)->getJson("{$this->url}/entries")->assertOk()->assertJsonCount(2, 'data');
    });
});

describe('validation on submit and posting (TE-FR-08, TE-AC-03)', function () {
    it('accepts an unbalanced draft but refuses to submit it without changing its status', function () {
        $id = createEntry(['lines' => [
            ['account_id' => $this->bank, 'debit' => '1000.00'],
            ['account_id' => $this->sales, 'credit' => '900.00'],
        ]]);

        entryAction($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonPath('code', 'UNBALANCED_ENTRY');

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.status', 'draft');
        expect(DB::table('te_entry_decisions')->count())->toBe(0);
    });

    it('refuses an unknown account and a period that does not accept postings', function () {
        $unknown = createEntry(['lines' => [
            ['account_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'debit' => '10.00'],
            ['account_id' => $this->sales, 'credit' => '10.00'],
        ]]);
        entryAction($this->preparer, $unknown, 'submit')->assertUnprocessable()->assertJsonPath('code', 'ACCOUNT_NOT_POSTABLE');

        $outside = createEntry(['accounting_date' => '2030-01-01']);
        entryAction($this->preparer, $outside, 'submit')->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
    });

    it('revalidates on posting: a period closed after approval blocks the journal and leaves the entry approved', function () {
        $id = createEntry();
        entryAction($this->preparer, $id, 'submit')->assertOk();
        entryAction($this->approver, $id, 'approve')->assertOk();

        $periodId = $this->actingAs($this->admin)->getJson("{$this->url}/periods?filter[date]=2026-03-15")->json('data.0.id');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$periodId}/close")->assertOk();

        entryAction($this->approver, $id, 'post')->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.journal_id', null);
        expect(DB::table('gl_journals')->count())->toBe(0);
    });

    it('rejects a malformed draft before it is stored', function () {
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", entryPayload(['lines' => 'x', 'accounting_date' => '15-03-2026']))
            ->assertUnprocessable()->assertJsonValidationErrors(['lines', 'accounting_date']);
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", entryPayload(['transaction_type_key' => 'nope']))
            ->assertUnprocessable()->assertJsonValidationErrors('transaction_type_key');
        expect(DB::table('te_entries')->count())->toBe(0);
    });
});

describe('transaction type versions (TE-FR-02)', function () {
    it('refuses new entries on a retired version but keeps old entries readable', function () {
        $id = createEntry();
        TransactionTypeVersion::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->update(['status' => VersionStatus::Retired]);

        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", entryPayload())
            ->assertUnprocessable()->assertJsonPath('code', 'TRANSACTION_TYPE_VERSION_RETIRED');
        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$id}")->assertOk()->assertJsonPath('data.transaction_type_version', 1);
    });
});

describe('approval and permissions (TE-FR-09)', function () {
    it('lets only holders of the permission act', function () {
        $id = createEntry();

        entryAction($this->viewer, $id, 'submit')->assertForbidden();
        $this->actingAs($this->viewer)->postJson("{$this->url}/entries", entryPayload())->assertForbidden();
        entryAction($this->preparer, $id, 'submit')->assertOk();
        entryAction($this->preparer, $id, 'approve')->assertForbidden();
        entryAction($this->preparer, $id, 'reject', ['reason' => 'x'])->assertForbidden();
    });

    it('blocks self-approval when separation of duties is on', function () {
        config(['entries.separation_of_duties' => true]);
        $both = memberWithRoles($this->companyId, 'preparer', 'approver');

        $id = $this->actingAs($both)->postJson("{$this->url}/entries", entryPayload())->assertCreated()->json('data.id');
        entryAction($both, $id, 'submit')->assertOk();

        entryAction($both, $id, 'approve')->assertForbidden()->assertJsonPath('code', 'SEGREGATION_OF_DUTIES');
        entryAction($this->approver, $id, 'approve')->assertOk();
    });

    it('does not show entries of another company', function () {
        $id = createEntry();
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/entries/{$id}")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/companies/{$this->companyId}/entries/{$id}")->assertForbidden();
    });

    it('stops writes while the company is read-only', function () {
        $id = createEntry();
        DB::table('md_companies')->where('id', $this->companyId)->update(['status' => 'inactive']);

        entryAction($this->preparer, $id, 'submit')->assertConflict()->assertJsonPath('code', 'COMPANY_READ_ONLY');
    });
});
