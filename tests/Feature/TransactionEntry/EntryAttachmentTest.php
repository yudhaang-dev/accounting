<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('attachments.disk'));

    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');
    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
});

function attachmentEntry(): string
{
    $account = fn (string $code) => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');

    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', [
        'transaction_type_key' => 'general_journal',
        'accounting_date' => '2026-03-15',
        'description' => 'Penjualan tunai',
        'lines' => [
            ['account_id' => $account('1112'), 'debit' => '1000.00'],
            ['account_id' => $account('4110'), 'credit' => '1000.00'],
        ],
    ])->assertCreated()->json('data.id');
}

function attach(string $entryId, ?UploadedFile $file = null, ?User $as = null)
{
    return test()->actingAs($as ?? test()->preparer)->post(
        test()->url."/entries/{$entryId}/attachments",
        ['file' => $file ?? UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\ninvoice")],
        ['Accept' => 'application/json'],
    );
}

describe('entry attachments (X-FR-04)', function () {
    it('stores the file with its content hash and lists and downloads it', function () {
        $entry = attachmentEntry();

        $id = attach($entry)->assertCreated()
            ->assertJsonPath('data.filename', 'invoice.pdf')
            ->assertJsonPath('data.sha256', hash('sha256', "%PDF-1.4\ninvoice"))
            ->assertJsonPath('data.size_bytes', strlen("%PDF-1.4\ninvoice"))
            ->json('data.id');

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$entry}/attachments")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $download = $this->actingAs($this->viewer)->get("{$this->url}/entries/{$entry}/attachments/{$id}")->assertOk();
        expect($download->streamedContent())->toBe("%PDF-1.4\ninvoice");
    });

    it('lets only users who can create entries add or remove, and keeps the files private', function () {
        $entry = attachmentEntry();

        attach($entry, as: $this->viewer)->assertForbidden();

        $id = attach($entry)->assertCreated()->json('data.id');

        $this->actingAs($this->viewer)->deleteJson("{$this->url}/entries/{$entry}/attachments/{$id}")->assertForbidden();
        expect(Storage::disk(config('attachments.disk'))->allFiles())->toHaveCount(1);
    });

    it('refuses a file type that is not allowed and a file that is too large', function () {
        $entry = attachmentEntry();

        attach($entry, UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'))->assertUnprocessable()->assertJsonValidationErrors('file');
        attach($entry, UploadedFile::fake()->create('big.pdf', config('attachments.max_kilobytes') + 1, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$entry}/attachments")->assertJsonCount(0, 'data');
    });

    it('keeps a removed attachment on record but hides it, only while the entry is being prepared', function () {
        $entry = attachmentEntry();
        $id = attach($entry)->assertCreated()->json('data.id');

        $this->actingAs($this->preparer)->deleteJson("{$this->url}/entries/{$entry}/attachments/{$id}")->assertNoContent();

        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$entry}/attachments")->assertJsonCount(0, 'data');
        $this->actingAs($this->viewer)->get("{$this->url}/entries/{$entry}/attachments/{$id}")->assertNotFound();
        expect(DB::table('pf_attachments')->where('id', $id)->whereNotNull('removed_at')->count())->toBe(1);

        $kept = attach($entry)->assertCreated()->json('data.id');
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$entry}/submit")->assertOk();

        $this->actingAs($this->preparer)->deleteJson("{$this->url}/entries/{$entry}/attachments/{$kept}")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('accepts evidence while the entry waits for approval and refuses any after posting', function () {
        $entry = attachmentEntry();
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$entry}/submit")->assertOk();

        attach($entry)->assertCreated();

        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$entry}/approve")->assertOk();
        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$entry}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        attach($entry)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        $this->actingAs($this->viewer)->getJson("{$this->url}/entries/{$entry}/attachments")->assertJsonCount(1, 'data');
    });

    it('does not serve an attachment through another entry', function () {
        $first = attachmentEntry();
        $second = attachmentEntry();
        $id = attach($first)->assertCreated()->json('data.id');

        $this->actingAs($this->viewer)->get("{$this->url}/entries/{$second}/attachments/{$id}")->assertNotFound();
    });

    it('refuses to delete the record of an attachment (X-FR-05)', function () {
        $entry = attachmentEntry();
        attach($entry)->assertCreated();

        expect(fn () => DB::table('pf_attachments')->delete())->toThrow(QueryException::class);
    });

    it('records who added and removed it in the audit log', function () {
        $entry = attachmentEntry();
        $id = attach($entry)->assertCreated()->json('data.id');
        $this->actingAs($this->preparer)->deleteJson("{$this->url}/entries/{$entry}/attachments/{$id}")->assertNoContent();

        expect(DB::table('pf_audit_log')->where('subject_id', $id)->orderBy('created_at')->pluck('event')->all())->toBe(['attachment.added', 'attachment.removed']);
    });
});
