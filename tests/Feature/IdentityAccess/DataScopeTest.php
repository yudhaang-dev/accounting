<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\IdentityAccess\Application\EffectiveAccessContexts;
use App\Modules\IdentityAccess\Application\WorkspaceInvitationNotification;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\Notification;

/*
| Data scope (IA-FR-03, IA-AC-04, X-AC-01). Company with a hierarchical `department` type
| (SALES > SALES-ID, FIN) and a `project` type, journals posted per department, and members limited
| to some of those values.
*/
beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = scopeAccountId($this->companyId, '1112');
    $this->sales = scopeAccountId($this->companyId, '4110');

    // `department` (hierarchical) and `project` are seeded with every company.
    $department = scopeSeededType($this->url, 'department');
    $project = scopeSeededType($this->url, 'project');
    $this->departmentType = $department;

    $this->sale = scopeDimension('dimension-values', ['dimension_type_id' => $department, 'code' => 'SALES', 'name' => 'Sales']);
    $this->saleId = scopeDimension('dimension-values', ['dimension_type_id' => $department, 'code' => 'SALES-ID', 'name' => 'Sales Indonesia', 'parent_id' => $this->sale]);
    $this->finance = scopeDimension('dimension-values', ['dimension_type_id' => $department, 'code' => 'FIN', 'name' => 'Finance']);
    $this->alpha = scopeDimension('dimension-values', ['dimension_type_id' => $project, 'code' => 'ALPHA', 'name' => 'Alpha']);
    $this->beta = scopeDimension('dimension-values', ['dimension_type_id' => $project, 'code' => 'BETA', 'name' => 'Beta']);

    // Sales Indonesia 1000 (project alpha), Finance 200 (project beta), and one journal across both.
    scopePost($this->companyId, '2026-02-05', '1000.00', ['department' => $this->saleId, 'project' => $this->alpha]);
    scopePost($this->companyId, '2026-02-10', '200.00', ['department' => $this->finance, 'project' => $this->beta]);
    app(PostingService::class)->post(new PostJournal(
        companyId: $this->companyId,
        accountingDate: '2026-02-15',
        description: 'Mixed',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine($this->bank, '300.00', '0', dimensions: ['department' => $this->saleId, 'project' => $this->alpha]),
            new PostJournalLine($this->sales, '0', '300.00', dimensions: ['department' => $this->finance, 'project' => $this->beta]),
        ],
    ));

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

/** A balanced journal where both lines carry the same dimensions. */
function scopePost(string $companyId, string $date, string $amount, array $dimensions): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine(test()->bank, $amount, '0', dimensions: $dimensions),
            new PostJournalLine(test()->sales, '0', $amount, dimensions: $dimensions),
        ],
    ));
}

function scopeRoleId(string $companyId, string $name): string
{
    return Role::where('company_id', $companyId)->where('name', $name)->value('id');
}

describe('assigning a data scope (IA-FR-03)', function () {
    it('stores the scope on the membership, audits it and bumps the version', function () {
        $member = memberWithRoles($this->companyId, 'viewer');

        limitTo($member, [$this->sale, $this->alpha])->assertOk()
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.data_scope', collect([$this->sale, $this->alpha])->sort()->values()->all());

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'membership.updated')->latest('id')->first();
        expect($audit->attribute_changes['old']['data_scope'])->toBe([])
            ->and($audit->attribute_changes['attributes']['data_scope'])->toHaveCount(2);

        $this->actingAs($this->admin)->getJson("{$this->url}/memberships")->assertOk()
            ->assertJsonPath('data.0.data_scope', fn ($scope) => is_array($scope));

        $membership = Membership::where('user_id', $member->id)->first();
        $this->actingAs($this->admin)->patchJson("{$this->url}/memberships/{$membership->id}", ['lock_version' => 1, 'data_scope' => []])
            ->assertConflict()->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->actingAs($this->admin)->patchJson("{$this->url}/memberships/{$membership->id}", ['lock_version' => 2, 'data_scope' => []])
            ->assertOk()->assertJsonPath('data.data_scope', []);
    });

    it('refuses a value of another company or an unknown one', function () {
        $member = memberWithRoles($this->companyId, 'viewer');
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $foreign = scopeSeededType("/api/v1/companies/{$otherCompany}", 'department', $other);
        $foreignValue = $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/dimension-values", ['dimension_type_id' => $foreign, 'code' => 'X', 'name' => 'X'])->json('data.id');

        limitTo($member, [$foreignValue])->assertUnprocessable()->assertJson(['code' => 'DIMENSION_INVALID']);
        limitTo($member, ['01HZZZZZZZZZZZZZZZZZZZZZZZ'])->assertUnprocessable();

        expect(app(EffectiveAccessContexts::class)->forUser($member->id, $this->companyId)->dataScope->isRestricted())->toBeFalse();
    });

    it('adds the descendants of a value and follows the hierarchy as it changes', function () {
        $member = scopedMember('viewer', [$this->sale]);
        $scope = fn () => app(EffectiveAccessContexts::class)->forUser($member->id, $this->companyId)->dataScope;

        expect($scope()->allowed()['department'])->toEqualCanonicalizing([$this->sale, $this->saleId])
            ->and($scope()->allows(['department' => $this->saleId]))->toBeTrue()
            ->and($scope()->allows(['department' => $this->finance]))->toBeFalse()
            ->and($scope()->allows([]))->toBeFalse();

        // A value added below the scoped one later falls inside the scope without touching the member.
        $newChild = scopeDimension('dimension-values', ['dimension_type_id' => $this->departmentType, 'code' => 'SALES-SG', 'name' => 'Sales Singapore', 'parent_id' => $this->sale]);

        expect($scope()->allows(['department' => $newChild]))->toBeTrue();
    });

    it('lets only a user without a limit set limits', function () {
        $manager = memberWithRoles($this->companyId, 'accounting_admin');
        limitTo($manager, [$this->sale])->assertOk();
        $member = memberWithRoles($this->companyId, 'viewer');

        limitTo($member, [], $manager)->assertForbidden();
        limitTo($member, [$this->sale], $manager)->assertForbidden();
        $this->actingAs($manager)->postJson("{$this->url}/invitations", ['email' => 'new@example.test', 'role_ids' => [scopeRoleId($this->companyId, 'viewer')]])
            ->assertForbidden();
    });

    it('limits an invited member from the moment they accept', function () {
        Notification::fake();
        $guest = User::factory()->create(['email' => 'guest@example.test']);

        $this->actingAs($this->admin)->postJson("{$this->url}/invitations", [
            'email' => 'guest@example.test',
            'role_ids' => [scopeRoleId($this->companyId, 'viewer')],
            'data_scope' => [$this->finance],
        ])->assertCreated()->assertJsonPath('data.data_scope', [$this->finance]);

        $token = null;
        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->actingAs($guest)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();

        $rows = $this->actingAs($guest)->getJson("{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31")->assertOk()
            ->assertJsonPath('data.restricted', true)->json('data.rows');
        expect(collect($rows)->firstWhere('code', '1112')['debit'])->toBe('200.00');

        $this->actingAs($this->admin)->postJson("{$this->url}/invitations", [
            'email' => 'other@example.test',
            'role_ids' => [scopeRoleId($this->companyId, 'viewer')],
            'data_scope' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ'],
        ])->assertUnprocessable();
    });
});

describe('reports (IA-AC-04, API-AC-07)', function () {
    it('adds up only the lines inside the scope of a limited user and says so', function () {
        $sales = scopedMember('viewer', [$this->sale]);
        $url = "{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31";

        $full = $this->actingAs($this->viewer)->getJson($url)->assertOk()->assertJsonPath('data.restricted', false);
        expect(collect($full->json('data.rows'))->firstWhere('code', '1112')['debit'])->toBe('1500.00');

        $limited = $this->actingAs($sales)->getJson($url)->assertOk()->assertJsonPath('data.restricted', true)->assertJsonPath('data.balanced', false);
        $rows = collect($limited->json('data.rows'));

        // Sales Indonesia: 1000 of the first journal plus the 300 debit line of the mixed one.
        expect($rows->firstWhere('code', '1112')['debit'])->toBe('1300.00')
            ->and($rows->firstWhere('code', '4110')['credit'])->toBe('1000.00');
    });

    it('applies the scope on top of a filter and to every dimension type at once', function () {
        $member = scopedMember('viewer', [$this->saleId, $this->beta]);
        $url = "{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31";

        // Sales Indonesia AND project beta: no line has both.
        $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('data.rows', []);

        $department = scopedMember('viewer', [$this->finance]);
        $rows = $this->actingAs($department)->getJson("{$url}&dimensions[project]={$this->alpha}")->assertOk()->json('data.rows');
        expect($rows)->toBe([]);

        $rows = $this->actingAs($department)->getJson("{$url}&dimensions[project]={$this->beta}")->assertOk()->json('data.rows');
        expect(collect($rows)->firstWhere('code', '1112')['debit'])->toBe('200.00');
    });

    it('limits the CSV download the same way', function () {
        $member = scopedMember('viewer', [$this->finance]);

        $response = $this->actingAs($member)->get("{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31", ['Accept' => 'text/csv'])->assertOk();
        $lines = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));

        expect($lines[1])->toStartWith('1112,')->toContain(',200.00,')
            ->and(end($lines))->toStartWith(',"Total (restricted)",');
    });

    it('takes effect from the next request and is not carried to other users', function () {
        $member = memberWithRoles($this->companyId, 'viewer');
        $url = "{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31";

        $this->actingAs($member)->getJson($url)->assertJsonPath('data.restricted', false);
        limitTo($member, [$this->finance])->assertOk();
        $this->actingAs($member)->getJson($url)->assertJsonPath('data.restricted', true);
        $this->actingAs($this->viewer)->getJson($url)->assertJsonPath('data.restricted', false);
        limitTo($member, [])->assertOk();
        $this->actingAs($member)->getJson($url)->assertJsonPath('data.restricted', false);
    });
});

describe('journals', function () {
    it('lists only journals with a line inside the scope, and only those lines', function () {
        $finance = scopedMember('viewer', [$this->finance]);

        $journals = $this->actingAs($finance)->getJson("{$this->url}/journals")->assertOk()->assertJsonCount(2, 'data');

        expect($journals->json('data.*.description'))->toEqualCanonicalizing(['Test', 'Mixed'])
            ->and(collect($journals->json('data'))->every(fn (array $journal) => $journal['scope_restricted'] === true))->toBeTrue();

        $mixed = collect($journals->json('data'))->firstWhere('description', 'Mixed');
        expect($mixed['lines'])->toHaveCount(1)
            ->and($mixed['lines'][0]['credit'])->toBe('300.00')
            ->and($mixed['lines'][0]['dimensions']['department'])->toBe($this->finance);

        $this->actingAs($this->viewer)->getJson("{$this->url}/journals")->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.scope_restricted', false)->assertJsonCount(2, 'data.0.lines');
    });

    it('hides a journal without lines in scope as not found and combines with filters', function () {
        $finance = scopedMember('viewer', [$this->finance]);
        $salesJournal = $this->actingAs($this->viewer)->getJson("{$this->url}/journals?filter[dimensions][department]={$this->sale}")->assertJsonCount(2, 'data')->json('data');
        $onlySales = collect($salesJournal)->firstWhere('description', 'Test');

        $this->actingAs($finance)->getJson("{$this->url}/journals/{$onlySales['id']}")->assertNotFound();
        // One line cannot be in Sales and in the Finance scope at once, so the filter and the scope leave nothing.
        $this->actingAs($finance)->getJson("{$this->url}/journals?filter[dimensions][department]={$this->sale}")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($finance)->getJson("{$this->url}/journals?filter[dimensions][department]={$this->finance}")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($finance)->getJson("{$this->url}/journals?filter[account_id]={$this->bank}")->assertOk()->assertJsonCount(1, 'data');

        $mixedId = collect($salesJournal)->firstWhere('description', 'Mixed')['id'];
        $this->actingAs($finance)->getJson("{$this->url}/journals/{$mixedId}")->assertOk()->assertJsonCount(1, 'data.lines');
    });
});

describe('entries (X-AC-01)', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
    });

    function scopedEntry(User $user, array $dimensions, string $amount = '100.00')
    {
        return test()->actingAs($user)->postJson(test()->url.'/entries', [
            'transaction_type_key' => 'general_journal',
            'accounting_date' => '2026-03-15',
            'description' => 'Scoped',
            'lines' => [
                ['account_id' => test()->bank, 'debit' => $amount, 'dimensions' => $dimensions],
                ['account_id' => test()->sales, 'credit' => $amount, 'dimensions' => $dimensions],
            ],
        ]);
    }

    it('lets a limited preparer create, change and carry an entry inside their scope to a journal', function () {
        $preparer = scopedMember('preparer', [$this->sale]);
        $approver = scopedMember('approver', [$this->sale]);

        $id = scopedEntry($preparer, ['department' => $this->saleId])->assertCreated()->json('data.id');
        $this->actingAs($preparer)->getJson("{$this->url}/entries/{$id}")->assertOk();
        $this->actingAs($preparer)->getJson("{$this->url}/entries")->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($preparer)->postJson("{$this->url}/entries/{$id}/submit")->assertOk();
        $this->actingAs($approver)->postJson("{$this->url}/entries/{$id}/approve")->assertOk();
        $this->actingAs($approver)->postJson("{$this->url}/entries/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    });

    it('refuses lines outside the scope or without the limited dimension when creating or changing a draft', function () {
        $preparer = scopedMember('preparer', [$this->sale]);

        scopedEntry($preparer, ['department' => $this->finance])->assertForbidden()->assertJson(['code' => 'FORBIDDEN'])
            ->assertJsonStructure(['errors' => ['lines.0.dimensions']]);
        scopedEntry($preparer, [])->assertForbidden();
        scopedEntry($preparer, ['project' => $this->alpha])->assertForbidden();

        $id = scopedEntry($preparer, ['department' => $this->saleId])->assertCreated()->json('data.id');
        $this->actingAs($preparer)->patchJson("{$this->url}/entries/{$id}", [
            'lock_version' => 1,
            'accounting_date' => '2026-03-15',
            'description' => 'Moved',
            'lines' => [
                ['account_id' => $this->bank, 'debit' => '100.00', 'dimensions' => ['department' => $this->finance]],
                ['account_id' => $this->sales, 'credit' => '100.00', 'dimensions' => ['department' => $this->finance]],
            ],
        ])->assertForbidden();
    });

    it('treats entries with any line outside the scope as not found for every action', function () {
        $preparer = memberWithRoles($this->companyId, 'preparer');
        $approver = memberWithRoles($this->companyId, 'approver');
        $financeEntry = scopedEntry($preparer, ['department' => $this->finance])->assertCreated()->json('data.id');
        $mixedEntry = $this->actingAs($preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal',
            'accounting_date' => '2026-03-15',
            'description' => 'Mixed',
            'lines' => [
                ['account_id' => $this->bank, 'debit' => '50.00', 'dimensions' => ['department' => $this->saleId]],
                ['account_id' => $this->sales, 'credit' => '50.00', 'dimensions' => ['department' => $this->finance]],
            ],
        ])->assertCreated()->json('data.id');
        $this->actingAs($preparer)->postJson("{$this->url}/entries/{$financeEntry}/submit")->assertOk();

        limitTo($approver, [$this->sale])->assertOk();
        limitTo($preparer, [$this->sale])->assertOk();

        foreach ([$financeEntry, $mixedEntry] as $id) {
            $this->actingAs($preparer)->getJson("{$this->url}/entries/{$id}")->assertNotFound();
            $this->actingAs($approver)->postJson("{$this->url}/entries/{$id}/approve")->assertNotFound();
            $this->actingAs($approver)->postJson("{$this->url}/entries/{$id}/post")->assertNotFound();
            $this->actingAs($preparer)->postJson("{$this->url}/entries/{$id}/cancel")->assertNotFound();
        }

        $this->actingAs($preparer)->getJson("{$this->url}/entries")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/entries")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->postJson("{$this->url}/entries/{$financeEntry}/approve")->assertOk();
    });
});
