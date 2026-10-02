<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

const OVERRIDE_PERMISSION = 'journals.override-source';

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = overrideAccountId($this->companyId, '1112');
    $this->sales = overrideAccountId($this->companyId, '4110');

    // The bank account takes subledger postings only, like a control account.
    $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->bank}", ['lock_version' => 1, 'allowed_sources' => ['subledger']])->assertOk();
});

function overrideAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function allowOverride(?string $permission = OVERRIDE_PERMISSION, int $version = 0)
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/ledger-policy', [
        'lock_version' => $version,
        'soft_closed_transaction_type_keys' => [],
        'soft_closed_permission' => null,
        'source_override_permission' => $permission,
        'fx_rounding_tolerance' => '0',
    ]);
}

function entryToBank(array $overrides = []): PostJournal
{
    return new PostJournal(...array_merge([
        'companyId' => test()->companyId,
        'accountingDate' => '2026-03-15',
        'description' => 'Manual bank entry',
        'source' => JournalSource::Entry,
        'lines' => [new PostJournalLine(test()->bank, '500.00', '0'), new PostJournalLine(test()->sales, '0', '500.00')],
    ], $overrides));
}

function overrideFailure(PostJournal $command): ApiException|ValidationException
{
    try {
        app(PostingService::class)->post($command);
    } catch (ApiException|ValidationException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the posting to fail.');
}

describe('control account override (GL-FR-07)', function () {
    it('refuses a posting from another source by default, whatever permission the actor holds', function () {
        $failure = overrideFailure(entryToBank(['actorPermissions' => [OVERRIDE_PERMISSION], 'sourceOverrideReason' => 'Urgent']));

        expect($failure)->toBeInstanceOf(ApiException::class)
            ->and($failure->errorCode->value)->toBe('POSTING_SOURCE_NOT_ALLOWED')
            ->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('refuses it when the policy allows an override but the actor lacks the permission', function () {
        allowOverride()->assertOk()->assertJsonPath('data.source_override_permission', OVERRIDE_PERMISSION);

        $failure = overrideFailure(entryToBank(['actorPermissions' => ['journals.read'], 'sourceOverrideReason' => 'Urgent']));

        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('POSTING_SOURCE_NOT_ALLOWED');
    });

    it('requires a reason from an actor who may override', function () {
        allowOverride()->assertOk();

        foreach ([null, '   '] as $reason) {
            $failure = overrideFailure(entryToBank(['actorPermissions' => [OVERRIDE_PERMISSION], 'sourceOverrideReason' => $reason]));

            expect($failure)->toBeInstanceOf(ValidationException::class)
                ->and($failure->errors())->toHaveKey('source_override_reason');
        }

        expect(DB::table('gl_journals')->count())->toBe(0);
    });

    it('posts with the reason, marks the journal and audits the override', function () {
        allowOverride()->assertOk();

        $posted = app(PostingService::class)->post(entryToBank([
            'actorPermissions' => [OVERRIDE_PERMISSION],
            'sourceOverrideReason' => '  Bank fee booked by hand  ',
            'postedBy' => $this->admin->id,
        ]));

        expect(DB::table('gl_journals')->where('id', $posted->id)->value('source_override_reason'))->toBe('Bank fee booked by hand');

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'journal.source_override')->sole();

        expect($audit->properties['reason'])->toBe('Bank fee booked by hand')
            ->and($audit->properties['accounts'])->toBe(['1112'])
            ->and($audit->properties['source'])->toBe('entry');

        $this->actingAs($this->admin)->getJson("{$this->url}/journals/{$posted->id}")
            ->assertOk()
            ->assertJsonPath('data.source_override_reason', 'Bank fee booked by hand');
    });

    it('leaves a posting that needs no override unmarked and unaudited, even with a reason', function () {
        allowOverride()->assertOk();

        $posted = app(PostingService::class)->post(entryToBank([
            'source' => JournalSource::Subledger,
            'actorPermissions' => [OVERRIDE_PERMISSION],
            'sourceOverrideReason' => 'Not needed',
        ]));

        expect(DB::table('gl_journals')->where('id', $posted->id)->value('source_override_reason'))->toBeNull()
            ->and(AuditEntry::where('event', 'journal.source_override')->count())->toBe(0);
    });

    it('does not let an override hide other failures', function () {
        allowOverride()->assertOk();

        $failure = overrideFailure(entryToBank([
            'accountingDate' => '2031-01-01',
            'actorPermissions' => [OVERRIDE_PERMISSION],
            'sourceOverrideReason' => 'Urgent',
        ]));

        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('PERIOD_NOT_OPEN');
    });

    it('takes the override away again with a new policy version, and rejects a permission that does not exist', function () {
        allowOverride()->assertOk();
        allowOverride('journals.nonexistent', 1)->assertUnprocessable()->assertJsonValidationErrors(['source_override_permission']);
        allowOverride(null, 1)->assertOk()->assertJsonPath('data.source_override_permission', null)->assertJsonPath('data.version', 2);

        $failure = overrideFailure(entryToBank(['actorPermissions' => [OVERRIDE_PERMISSION], 'sourceOverrideReason' => 'Urgent']));

        expect($failure)->toBeInstanceOf(ApiException::class)->and($failure->errorCode->value)->toBe('POSTING_SOURCE_NOT_ALLOWED');
    });
});
