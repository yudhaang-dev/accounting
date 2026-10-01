<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Audit\AuditEntry;
use App\Platform\Retention\RetentionGuard;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
    $this->accounts = "{$this->url}/accounts";
});

function postAccount(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->accounts, array_merge([
        'code' => '1000',
        'name' => 'Assets',
        'class' => 'asset',
    ], $overrides));
}

describe('creating accounts (GL-FR-01)', function () {
    it('defaults the saldo normal to the class and starts active with lock_version 1', function () {
        postAccount()->assertCreated()->assertJson(['data' => [
            'code' => '1000', 'name' => 'Assets', 'class' => 'asset', 'normal_balance' => 'debit',
            'parent_id' => null, 'is_leaf' => true, 'status' => 'active', 'lock_version' => 1,
        ]]);

        foreach (['liability' => 'credit', 'equity' => 'credit', 'income' => 'credit', 'expense' => 'debit'] as $class => $normal) {
            postAccount(['code' => "c-{$class}", 'class' => $class])->assertCreated()->assertJsonPath('data.normal_balance', $normal);
        }
    });

    it('allows a contra account with the opposite saldo normal', function () {
        postAccount(['code' => '1219', 'name' => 'Accumulated depreciation', 'normal_balance' => 'credit'])
            ->assertCreated()->assertJsonPath('data.normal_balance', 'credit');
    });

    it('keeps codes unique per company, but another company may reuse them (X-AC-02)', function () {
        postAccount()->assertCreated();
        postAccount(['name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/accounts", ['code' => '1000', 'name' => 'Theirs', 'class' => 'asset'])
            ->assertCreated();
    });

    it('requires a parent that is active, in the same company and of the same class', function () {
        $parent = postAccount()->json('data.id');

        postAccount(['code' => '1100', 'parent_id' => $parent])->assertCreated()->assertJsonPath('data.parent_id', $parent);
        postAccount(['code' => '2100', 'class' => 'liability', 'parent_id' => $parent])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        postAccount(['code' => '1200', 'parent_id' => '01J00000000000000000000000'])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $foreign = $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/accounts", ['code' => '1000', 'name' => 'Theirs', 'class' => 'asset'])->json('data.id');

        postAccount(['code' => '1300', 'parent_id' => $foreign])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$parent}/deactivate")->assertStatus(409);
    });

    it('rejects a parent that was deactivated', function () {
        $parent = postAccount()->json('data.id');
        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$parent}/deactivate")->assertOk();

        postAccount(['code' => '1100', 'parent_id' => $parent])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    });

    it('validates the payload and audits the creation', function () {
        postAccount(['code' => 'with space', 'class' => 'revenue', 'normal_balance' => 'left'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'class', 'normal_balance']);

        $id = postAccount()->json('data.id');

        expect(AuditEntry::where('event', 'account.created')->where('company_id', $this->companyId)->count())->toBe(1)
            ->and(Account::withoutGlobalScope(CompanyScope::class)->find($id)->company_id)->toBe($this->companyId);
    });
});

describe('reading accounts (GL-FR-01)', function () {
    beforeEach(function () {
        $this->root = postAccount()->json('data.id');
        postAccount(['code' => '1100', 'name' => 'Current assets', 'parent_id' => $this->root]);
        postAccount(['code' => '2000', 'name' => 'Liabilities', 'class' => 'liability']);
    });

    it('lists by code with is_leaf, filters and pagination', function () {
        $list = $this->actingAs($this->admin)->getJson($this->accounts)->assertOk();

        expect(array_column($list->json('data'), 'code'))->toBe(['1000', '1100', '2000'])
            ->and(array_column($list->json('data'), 'is_leaf'))->toBe([false, true, true])
            ->and($list->json('meta.total'))->toBe(3);

        $this->actingAs($this->admin)->getJson("{$this->accounts}?filter[class]=liability")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->accounts}?filter[parent_id]={$this->root}")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->accounts}?filter[search]=current")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->accounts}?per_page=2")->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
    });

    it('never shows accounts of another company', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/accounts", ['code' => '9999', 'name' => 'Theirs', 'class' => 'asset']);

        expect(array_column($this->actingAs($this->admin)->getJson($this->accounts)->json('data'), 'code'))->not->toContain('9999');
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/accounts/{$this->root}")->assertNotFound();
    });
});

describe('updating accounts (GL-FR-01)', function () {
    it('changes code, name and saldo normal, and bumps lock_version', function () {
        $id = postAccount()->json('data.id');

        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$id}", ['lock_version' => 1, 'name' => 'Renamed', 'code' => '1001', 'normal_balance' => 'credit'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.code', '1001')->assertJsonPath('data.lock_version', 2);

        expect(AuditEntry::where('event', 'account.updated')->count())->toBe(1);
    });

    it('answers VERSION_CONFLICT for a stale lock_version and refuses a taken code', function () {
        $id = postAccount()->json('data.id');
        postAccount(['code' => '1100']);

        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$id}", ['lock_version' => 1, 'name' => 'First'])->assertOk();
        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$id}", ['lock_version' => 1, 'name' => 'Second'])
            ->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$id}", ['lock_version' => 2, 'code' => '1100'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    });

    it('does not change the class', function () {
        $id = postAccount()->json('data.id');

        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$id}", ['lock_version' => 1, 'class' => 'liability', 'name' => 'Still asset'])->assertOk();

        expect(Account::withoutGlobalScope(CompanyScope::class)->find($id)->class)->toBe(AccountClass::Asset);
    });

    it('moves an account to another parent but refuses cycles and other classes', function () {
        $a = postAccount(['code' => '1000'])->json('data.id');
        $b = postAccount(['code' => '1100', 'parent_id' => $a])->json('data.id');
        $c = postAccount(['code' => '1110', 'parent_id' => $b])->json('data.id');
        $liability = postAccount(['code' => '2000', 'class' => 'liability'])->json('data.id');

        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$a}", ['lock_version' => 1, 'parent_id' => $c])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$a}", ['lock_version' => 1, 'parent_id' => $a])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$c}", ['lock_version' => 1, 'parent_id' => $liability])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$c}", ['lock_version' => 1, 'parent_id' => $a])
            ->assertOk()->assertJsonPath('data.parent_id', $a);
        $this->actingAs($this->admin)->patchJson("{$this->accounts}/{$c}", ['lock_version' => 2, 'parent_id' => null])
            ->assertOk()->assertJsonPath('data.parent_id', null);
    });
});

describe('deactivating accounts (GL-FR-01)', function () {
    it('deactivates a leaf, refuses a parent with active children and reactivation under an inactive parent', function () {
        $parent = postAccount()->json('data.id');
        $child = postAccount(['code' => '1100', 'parent_id' => $parent])->json('data.id');

        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$parent}/deactivate")
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);

        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$child}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$child}/deactivate")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$parent}/deactivate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$child}/reactivate")->assertStatus(409);

        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$parent}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$child}/reactivate")->assertOk();

        expect(AuditEntry::whereIn('event', ['account.deactivated', 'account.reactivated'])->count())->toBe(4);
    });

    it('has no delete route and the database refuses hard deletes', function () {
        $id = postAccount()->json('data.id');

        $this->actingAs($this->admin)->deleteJson("{$this->accounts}/{$id}")->assertStatus(405);

        expect(RetentionGuard::protectedTables())->toContain('gl_accounts')
            ->and(fn () => DB::table('gl_accounts')->where('id', $id)->delete())->toThrow(QueryException::class);
    });

    it('enforces class, saldo normal and status values in the database', function () {
        $id = postAccount()->json('data.id');

        expect(fn () => DB::table('gl_accounts')->where('id', $id)->update(['class' => 'revenue']))->toThrow(QueryException::class);
    });
});

describe('access', function () {
    it('lets a viewer read the chart but not change it, and keeps strangers out', function () {
        postAccount();
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson($this->accounts)->assertOk();
        $this->actingAs($viewer)->postJson($this->accounts, ['code' => '2000', 'name' => 'X', 'class' => 'liability'])->assertForbidden();
        $this->actingAs($viewer)->getJson("{$this->url}/account-templates")->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($this->accounts)->assertForbidden();
    });

    it('refuses changes once the company is inactive', function () {
        confirmChartOfAccounts($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk();

        $this->actingAs($this->admin)->getJson($this->accounts)->assertOk();
        postAccount(['code' => '9000'])->assertStatus(409)->assertJson(['code' => 'COMPANY_READ_ONLY']);
    });
});

describe('chart of accounts template (X-AC-03)', function () {
    $template = require __DIR__.'/../../../app/Modules/GeneralLedger/Database/templates/general-id.php';

    it('is never applied on its own: a new company starts with an empty chart', function () {
        expect(Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->count())->toBe(0);
        $this->actingAs($this->admin)->getJson($this->accounts)->assertOk()->assertJsonCount(0, 'data');
    });

    it('can be reviewed before it is confirmed', function () use ($template) {
        $this->actingAs($this->admin)->getJson("{$this->url}/account-templates")
            ->assertOk()->assertJsonPath('data.0.key', 'general-id')->assertJsonPath('data.0.account_count', count($template['accounts']));

        $this->actingAs($this->admin)->getJson("{$this->url}/account-templates/general-id")
            ->assertOk()->assertJsonCount(count($template['accounts']), 'data.accounts');
        $this->actingAs($this->admin)->getJson("{$this->url}/account-templates/unknown")->assertNotFound();
    });

    it('is refused without an explicit confirmation', function () {
        $import = fn (array $body) => $this->actingAs($this->admin)->postJson("{$this->accounts}/import-template", $body);

        $import(['template' => 'general-id'])->assertUnprocessable()->assertJsonValidationErrors(['confirmed']);
        $import(['template' => 'general-id', 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors(['confirmed']);
        $import(['template' => 'nope', 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors(['template']);

        expect(Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->count())->toBe(0);
    });

    it('creates the whole confirmed template with its hierarchy and audits it', function () use ($template) {
        $this->actingAs($this->admin)->postJson("{$this->accounts}/import-template", ['template' => 'general-id', 'confirmed' => true])
            ->assertCreated()->assertJsonPath('data.created', count($template['accounts']));

        $byCode = Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->get()->keyBy('code');

        expect($byCode)->toHaveCount(count($template['accounts']));

        foreach ($template['accounts'] as $row) {
            $account = $byCode[$row['code']];

            expect($account->class->value)->toBe($row['class'])
                ->and($account->parent_id)->toBe($row['parent'] === null ? null : $byCode[$row['parent']]->id)
                ->and($account->normal_balance)->toBe($row['normal'] ?? AccountClass::from($row['class'])->defaultNormalBalance());
        }

        $audit = AuditEntry::where('event', 'accounts.template_imported')->where('company_id', $this->companyId)->sole();

        expect($audit->properties['template'])->toBe('general-id')
            ->and($audit->properties['created'])->toBe(count($template['accounts']));
    });

    it('leaves out optional leaf accounts only', function () use ($template) {
        $import = fn (array $exclude) => $this->actingAs($this->admin)->postJson("{$this->accounts}/import-template", [
            'template' => 'general-id', 'confirmed' => true, 'exclude' => $exclude,
        ]);

        $import(['1121'])->assertUnprocessable()->assertJsonValidationErrors(['exclude.0']);   // required, not optional
        $import(['1100'])->assertUnprocessable()->assertJsonValidationErrors(['exclude.0']);   // parent
        $import(['0000'])->assertUnprocessable()->assertJsonValidationErrors(['exclude.0']);   // unknown

        $import(['1113', '1114'])->assertCreated()->assertJsonPath('data.created', count($template['accounts']) - 2);

        expect(Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('code', ['1113', '1114'])->count())->toBe(0);
    });

    it('can only be imported into an empty chart', function () {
        postAccount()->assertCreated();

        $this->actingAs($this->admin)->postJson("{$this->accounts}/import-template", ['template' => 'general-id', 'confirmed' => true])
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);
    });

    it('keeps every account of CHART-OF-ACCOUNTS.md and a consistent hierarchy', function () use ($template) {
        preg_match_all('/^\|\s*(\d{4})\s*\|/m', explode('## Akun', file_get_contents(base_path('.docs/CHART-OF-ACCOUNTS.md')), 2)[1], $matches);
        $codes = array_column($template['accounts'], 'code');

        expect($codes)->toBe($matches[1])->and(array_unique($codes))->toBe($codes);

        $byCode = array_column($template['accounts'], null, 'code');

        foreach ($template['accounts'] as $row) {
            expect(AccountClass::tryFrom($row['class']))->not->toBeNull($row['code']);

            if ($row['parent'] !== null) {
                expect($byCode)->toHaveKey($row['parent'])
                    ->and($byCode[$row['parent']]['class'])->toBe($row['class'], $row['code'])
                    ->and($row['parent'] < $row['code'])->toBeTrue($row['code']);
            }
        }
    });
});

describe('activation prerequisite (GL-FR-01, X-AC-03)', function () {
    it('refuses to activate without a usable chart and names the missing classes', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")
            ->assertUnprocessable()
            ->assertJson(['code' => 'COMPANY_NOT_READY'])
            ->assertJsonValidationErrors(['chart_of_accounts']);

        postAccount(['code' => '1000', 'class' => 'asset']);
        postAccount(['code' => '2000', 'class' => 'liability']);

        $response = $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertUnprocessable();

        expect($response->json('errors.chart_of_accounts.0'))->toContain('equity')->toContain('income')->toContain('expenses')
            ->not->toContain('liabilities');
    });

    it('counts only active leaf accounts, then activates', function () {
        foreach (['asset' => '1000', 'liability' => '2000', 'equity' => '3000', 'income' => '4000'] as $class => $code) {
            postAccount(['code' => $code, 'class' => $class]);
        }
        $parent = postAccount(['code' => '5000', 'class' => 'expense'])->json('data.id');
        postAccount(['code' => '5100', 'class' => 'expense', 'parent_id' => $parent]);

        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('does not count a class whose only leaf is inactive, nor a parent without postable children', function () {
        foreach (['asset' => '1000', 'liability' => '2000', 'equity' => '3000', 'income' => '4000', 'expense' => '5000'] as $class => $code) {
            postAccount(['code' => $code, 'class' => $class]);
        }

        $expense = Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '5000')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->accounts}/{$expense}/deactivate")->assertOk();

        expect(app(AccountDirectory::class)->missingClasses($this->companyId))->toBe([AccountClass::Expense]);
    });

    it('activates a company with the confirmed template', function () {
        confirmChartOfAccounts($this->admin, $this->companyId);

        expect(app(AccountDirectory::class)->missingClasses($this->companyId))->toBe([]);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
    });

    it('reads account metadata through the contract, per company', function () {
        $id = postAccount()->json('data.id');
        $child = postAccount(['code' => '1100', 'parent_id' => $id])->json('data.id');
        $directory = app(AccountDirectory::class);

        $parent = $directory->find($this->companyId, $id);

        expect($parent->code)->toBe('1000')->and($parent->isLeaf)->toBeFalse()->and($parent->isActive)->toBeTrue()
            ->and($directory->find($this->companyId, $child)->isLeaf)->toBeTrue()
            ->and($directory->find((string) Str::ulid(), $id))->toBeNull();
    });
});
