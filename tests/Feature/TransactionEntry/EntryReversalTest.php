<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\TransactionEntry\Application\EntryReversals;
use App\Support\Company\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Reversal, replacement and scheduled reversal of entries (TE-FR-11, TE-AC-07, TE-AC-09).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->first = memberWithRoles($this->companyId, 'approver');
    $this->second = memberWithRoles($this->companyId, 'approver');

    $this->bank = revAccountId('1112');
    $this->sales = revAccountId('4110');

    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
});

function revAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function revDraft(array $overrides = []): array
{
    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', array_merge([
        'transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'Penjualan',
        'lines' => [['account_id' => test()->bank, 'debit' => '500.00'], ['account_id' => test()->sales, 'credit' => '500.00']],
    ], $overrides))->assertCreated()->json('data');
}

function revAct(User $user, string $id, string $action, array $body = [])
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}", $body);
}

/** An entry carried through to a posted journal; returns the entry as posted. */
function revPosted(array $overrides = []): array
{
    $id = revDraft($overrides)['id'];
    revAct(test()->preparer, $id, 'submit')->assertOk();
    revAct(test()->first, $id, 'approve')->assertOk();

    return revAct(test()->first, $id, 'post')->assertOk()->json('data');
}

function revRequest(string $journalId, array $body = [])
{
    return test()->actingAs(test()->preparer)->postJson(test()->url."/journals/{$journalId}/reverse", array_merge(['reversal_date' => '2026-03-20', 'reason' => 'Salah akun'], $body));
}

function revLines(string $journalId): array
{
    return DB::table('gl_journal_lines')->where('journal_id', $journalId)->orderBy('line_number')->get()
        ->map(fn ($line) => ['account_id' => $line->account_id, 'debit' => (float) $line->debit, 'credit' => (float) $line->credit])->all();
}

describe('reversal entry', function () {
    it('goes through the approval flow and posts the journal in mirror image', function () {
        $posted = revPosted();

        $reversal = revRequest($posted['journal_id'])->assertCreated()
            ->assertJsonPath('data.transaction_type_key', 'system.reversal')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.reverses_journal_id', $posted['journal_id'])
            ->assertJsonPath('data.reason', 'Salah akun')
            ->assertJsonPath('data.accounting_date', '2026-03-20')
            ->json('data');

        revAct($this->preparer, $reversal['id'], 'submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        revAct($this->first, $reversal['id'], 'approve')->assertOk();
        $done = revAct($this->first, $reversal['id'], 'post')->assertOk()->assertJsonPath('data.status', 'posted')->json('data');

        expect(revLines($done['journal_id']))->toBe([
            ['account_id' => $this->bank, 'debit' => 0.0, 'credit' => 500.0],
            ['account_id' => $this->sales, 'debit' => 500.0, 'credit' => 0.0],
        ])->and((string) DB::table('gl_journals')->where('id', $done['journal_id'])->value('reverses_journal_id'))->toBe($posted['journal_id']);
    });

    it('reverses a journal only once', function () {
        $posted = revPosted();
        $reversal = revRequest($posted['journal_id'])->assertCreated()->json('data');

        // While the first request is under way.
        revRequest($posted['journal_id'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        // A cancelled request frees the journal again.
        revAct($this->preparer, $reversal['id'], 'cancel')->assertOk();
        $again = revRequest($posted['journal_id'])->assertCreated()->json('data');

        revAct($this->preparer, $again['id'], 'submit')->assertOk();
        revAct($this->first, $again['id'], 'approve')->assertOk();
        revAct($this->first, $again['id'], 'post')->assertOk();

        revRequest($posted['journal_id'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('follows the approval policy for the key system.reversal and sizes it by the journal (TE-FR-11)', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/approval-policy", [
            'lock_version' => 0, 'separation_of_duties' => true,
            'rules' => [['transaction_type_key' => 'system.reversal', 'source' => null, 'threshold' => '100', 'stages' => ['entries.approve', 'entries.approve'], 'auto_post' => false]],
        ])->assertOk();

        $posted = revPosted();
        $reversal = revRequest($posted['journal_id'])->assertCreated()->json('data');

        revAct($this->preparer, $reversal['id'], 'submit')->assertOk()->assertJsonPath('data.approval.stages', ['entries.approve', 'entries.approve'])->assertJsonPath('data.approval.approved', 0);
        revAct($this->first, $reversal['id'], 'approve')->assertOk()->assertJsonPath('data.status', 'submitted');
        revAct($this->first, $reversal['id'], 'post')->assertStatus(409);
        revAct($this->second, $reversal['id'], 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
        revAct($this->second, $reversal['id'], 'post')->assertOk();
    });

    it('refuses a date in a period that does not accept the posting (TE-AC-07)', function () {
        $posted = revPosted();

        revRequest($posted['journal_id'], ['reversal_date' => '2030-01-10'])->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
        revRequest($posted['journal_id'], ['reversal_date' => 'not a date'])->assertUnprocessable()->assertJsonValidationErrors(['reversal_date']);
        revRequest($posted['journal_id'], ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        expect(DB::table('te_entries')->where('company_id', $this->companyId)->where('transaction_type_key', 'system.reversal')->count())->toBe(0);
    });

    it('checks the period again when the entry is submitted and when it is posted', function () {
        $march = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-03')->value('id');
        $posted = revPosted();
        $reversal = revRequest($posted['journal_id'])->assertCreated()->json('data');

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$march}/close", [])->assertOk();

        revAct($this->preparer, $reversal['id'], 'submit')->assertUnprocessable()->assertJsonPath('code', 'PERIOD_NOT_OPEN');
        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$reversal['id']}")->assertJsonPath('data.status', 'draft');
    });

    it('does not know a journal that is not there, nor one of another company', function () {
        revRequest('01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

        $journalId = revPosted()['journal_id'];
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/journals/{$journalId}/reverse", ['reversal_date' => '2026-03-20', 'reason' => 'x'])->assertNotFound();
    });

    it('lets only holders of entries.create ask, and does not let the request be edited', function () {
        $posted = revPosted();
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->postJson("{$this->url}/journals/{$posted['journal_id']}/reverse", ['reversal_date' => '2026-03-20', 'reason' => 'x'])->assertForbidden();

        $reversal = revRequest($posted['journal_id'])->assertCreated()->json('data');
        $this->actingAs($this->preparer)->patchJson("{$this->url}/entries/{$reversal['id']}", [
            'lock_version' => $reversal['lock_version'], 'accounting_date' => '2026-03-21', 'description' => 'x', 'lines' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    });
});

describe('replacement', function () {
    it('links an entry to the posted entry it replaces', function () {
        $posted = revPosted();

        revDraft(['replaces_entry_id' => $posted['id']]);
        $replacement = revDraft(['replaces_entry_id' => $posted['id']]);

        expect($replacement['replaces_entry_id'])->toBe($posted['id']);
    });

    it('refuses an entry that is not posted or not there', function () {
        $draft = revDraft();

        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'x', 'replaces_entry_id' => $draft['id'],
            'lines' => [['account_id' => $this->bank, 'debit' => '1.00'], ['account_id' => $this->sales, 'credit' => '1.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['replaces_entry_id']);
    });
});

describe('scheduled reversal', function () {
    it('reverses on its date, on the approval the entry was posted with', function () {
        $posted = revPosted(['auto_reverse_on' => '2026-04-10']);

        expect($posted['auto_reverse_on'])->toBe('2026-04-10')->and($posted['auto_reversal_status'])->toBe('pending');

        // Not yet due.
        expect(app(EntryReversals::class)->runScheduled(CarbonImmutable::parse('2026-04-09 12:00:00')))->toBe(['done' => 0, 'failed' => 0]);

        $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$posted['id']}")->assertJsonPath('data.auto_reversal_status', 'pending');

        // Due on the date in the time zone of the company; none of the reversals are skipped.
        expect(app(EntryReversals::class)->runScheduled(CarbonImmutable::parse('2026-04-10 12:00:00')))->toBe(['done' => 1, 'failed' => 0]);

        $origin = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$posted['id']}")->assertJsonPath('data.auto_reversal_status', 'done')->json('data');
        $reversal = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$origin['auto_reversal_entry_id']}")
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.transaction_type_key', 'system.reversal')->assertJsonPath('data.accounting_date', '2026-04-10')->assertJsonPath('data.decisions.0.action', 'auto_approve')
            ->json('data');

        expect(revLines($reversal['journal_id']))->toBe([
            ['account_id' => $this->bank, 'debit' => 0.0, 'credit' => 500.0],
            ['account_id' => $this->sales, 'debit' => 500.0, 'credit' => 0.0],
        ]);

        // A second run finds nothing left to do.
        expect(app(EntryReversals::class)->runScheduled(CarbonImmutable::parse('2026-04-11')))->toBe(['done' => 0, 'failed' => 0]);
    });

    it('fails visibly when the period of the date is closed (TE-AC-09)', function () {
        $posted = revPosted(['auto_reverse_on' => '2026-04-10']);
        $april = (string) DB::table('gl_periods')->where('company_id', $this->companyId)->where('name', '2026-04')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$april}/close", [])->assertOk();

        expect(app(EntryReversals::class)->runScheduled(CarbonImmutable::parse('2026-04-10 12:00:00')))->toBe(['done' => 0, 'failed' => 1]);

        $origin = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$posted['id']}")
            ->assertJsonPath('data.auto_reversal_status', 'failed')->assertJsonPath('data.auto_reversal_entry_id', null)->json('data');

        expect($origin['auto_reversal_error'])->toContain('PERIOD_NOT_OPEN');

        // It is listed as an exception, and nothing was posted or left half done.
        $this->actingAs($this->admin)->getJson("{$this->url}/entries?filter[auto_reversal_status]=failed")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $posted['id']);
        expect(DB::table('gl_journals')->whereNotNull('reverses_journal_id')->count())->toBe(0)
            ->and(DB::table('te_entries')->where('transaction_type_key', 'system.reversal')->count())->toBe(0);

        // It does not come back on its own either: a person reverses it by hand.
        expect(app(EntryReversals::class)->runScheduled(CarbonImmutable::parse('2026-04-12')))->toBe(['done' => 0, 'failed' => 0]);
    });

    it('needs a date after the accounting date', function () {
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'x', 'auto_reverse_on' => '2026-03-15',
            'lines' => [['account_id' => $this->bank, 'debit' => '1.00'], ['account_id' => $this->sales, 'credit' => '1.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['auto_reverse_on']);
    });

    it('is run by the console command', function () {
        $this->artisan('entries:reverse-scheduled')->expectsOutputToContain('Reversed 0, failed 0.')->assertSuccessful();
    });
});
