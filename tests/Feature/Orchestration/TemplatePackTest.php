<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Reporting\Domain\ReportDefinition;
use App\Modules\Subledger\Domain\PartyRole;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Orchestration\Setup\TemplatePacks;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

function tpInstall(array $components, array $exclude = [], ?User $as = null, bool $confirmed = true)
{
    $body = ['components' => $components] + ($confirmed ? ['confirmed' => true] : []) + ($exclude === [] ? [] : ['exclude' => $exclude]);

    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/template-packs/general/install', $body);
}

function tpCount(string $model): int
{
    return $model::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->count();
}

/** @return list<string> */
function tpAllComponents(): array
{
    return ['chart_of_accounts', 'party_roles', 'transaction_types.general', 'transaction_types.daily-operations', 'report_definitions'];
}

describe('template packs (X-AC-06, ADR-0019)', function () {
    it('lists the released packs with the templates they put together', function () {
        $pack = collect($this->actingAs($this->admin)->getJson("{$this->url}/template-packs")->assertOk()->json('data'))->firstWhere('key', 'general');

        expect($pack['components'])->toHaveCount(5)
            ->and(array_column($pack['components'], 'kind'))->toBe(['chart_of_accounts', 'party_roles', 'transaction_types', 'transaction_types', 'report_definitions']);
    });

    it('shows what a pack would create without creating anything, and marks what the company has', function () {
        $show = fn () => $this->actingAs($this->admin)->getJson("{$this->url}/template-packs/general")->assertOk()->json('data.components');

        $before = collect($show());
        expect($before->firstWhere('id', 'chart_of_accounts')['items'])->not->toBeEmpty()
            ->and(collect($before->firstWhere('id', 'chart_of_accounts')['items'])->contains('exists', true))->toBeFalse()
            ->and(tpCount(Account::class))->toBe(0);

        confirmChartOfAccounts($this->admin, $this->companyId);

        expect(collect(collect($show())->firstWhere('id', 'chart_of_accounts')['items'])->every(fn (array $item) => $item['exists']))->toBeTrue();
        $this->actingAs($this->admin)->getJson("{$this->url}/template-packs/unknown")->assertNotFound();
    });

    it('installs every picked component in order, in one go, and records it in the audit', function () {
        $response = tpInstall(tpAllComponents())->assertCreated();

        expect(array_column($response->json('data.components'), 'id'))->toBe(tpAllComponents())
            ->and(tpCount(Account::class))->toBeGreaterThan(50)
            ->and(tpCount(PartyRole::class))->toBe(5)
            ->and(tpCount(TransactionType::class))->toBeGreaterThan(10)
            ->and(tpCount(ReportDefinition::class))->toBe(3);

        $entry = AuditEntry::query()->where('event', 'template_pack.installed')->latest('id')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['pack'])->toBe('general')
            ->and($entry->properties['components'])->toHaveCount(5);
    });

    it('creates nothing without a confirmation or for a component the pack lacks', function () {
        tpInstall(tpAllComponents(), confirmed: false)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        tpInstall(['chart_of_accounts', 'nope'])->assertUnprocessable()->assertJsonValidationErrors('components.1');
        tpInstall(['chart_of_accounts'], ['party_roles' => ['customer']])->assertUnprocessable()->assertJsonValidationErrors('exclude.party_roles');

        expect(tpCount(Account::class))->toBe(0);
    });

    it('leaves nothing behind when a later component fails', function () {
        // Without the customer role the daily types cannot be imported, after the chart and the roles were created.
        tpInstall(tpAllComponents(), ['party_roles' => ['customer']])->assertUnprocessable();

        expect(tpCount(Account::class))->toBe(0)
            ->and(tpCount(PartyRole::class))->toBe(0)
            ->and(tpCount(TransactionType::class))->toBe(0)
            ->and(tpCount(ReportDefinition::class))->toBe(0);
    });

    it('skips what the company already has and never overwrites it', function () {
        confirmChartOfAccounts($this->admin, $this->companyId);
        $account = Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '1111')->firstOrFail();
        $account->forceFill(['name' => 'Kas kecil saya'])->save();
        $accounts = tpCount(Account::class);

        $first = tpInstall(tpAllComponents())->assertCreated();

        expect(collect($first->json('data.components'))->firstWhere('id', 'chart_of_accounts')['created'])->toBe([])
            ->and(tpCount(Account::class))->toBe($accounts)
            ->and($account->refresh()->name)->toBe('Kas kecil saya')
            ->and(tpCount(ReportDefinition::class))->toBe(3);

        $again = tpInstall(tpAllComponents())->assertCreated();

        expect(collect($again->json('data.components'))->every(fn (array $component) => $component['created'] === []))->toBeTrue();
    });

    it('leaves out the optional items the caller excludes', function () {
        $optional = collect($this->actingAs($this->admin)->getJson("{$this->url}/template-packs/general")->json('data.components.0.items'))->firstWhere('optional', true)['key'];

        tpInstall(['chart_of_accounts'], ['chart_of_accounts' => [$optional]])->assertCreated();

        expect(Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', $optional)->exists())->toBeFalse();
    });

    it('refuses the whole install before creating anything when a picked component needs a permission the caller lacks', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/roles", ['name' => 'chart_only', 'permissions' => ['accounts.manage']])->assertCreated();
        $member = memberWithRoles($this->companyId, 'chart_only');

        $this->actingAs($member)->getJson("{$this->url}/template-packs")->assertOk();
        tpInstall(['chart_of_accounts', 'report_definitions'], as: $member)->assertForbidden();
        expect(tpCount(Account::class))->toBe(0);

        tpInstall(['chart_of_accounts'], as: $member)->assertCreated();
        expect(tpCount(Account::class))->toBeGreaterThan(0);

        $viewer = memberWithRoles($this->companyId, 'viewer');
        $this->actingAs($viewer)->getJson("{$this->url}/template-packs")->assertForbidden();
    });

    it('offers only reviewed packs and rejects a manifest listed out of order', function () {
        $dir = sys_get_temp_dir().'/packs-'.uniqid();
        mkdir($dir);
        $manifest = fn (string $key, bool $reviewed, array $components) => file_put_contents("{$dir}/{$key}.php", '<?php return '.var_export([
            'key' => $key, 'name' => $key, 'description' => '', 'version' => 1, 'reviewed' => $reviewed, 'components' => $components,
        ], true).';');

        $manifest('draft', false, [['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'general-id']]);
        $manifest('released', true, [['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'general-id']]);
        config(['templates.packs_path' => $dir]);

        expect(array_keys((new TemplatePacks)->all()))->toBe(['released']);

        $manifest('wrong-order', true, [
            ['id' => 'report_definitions', 'kind' => 'report_definitions', 'template' => 'general-id'],
            ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'general-id'],
        ]);

        expect(fn () => (new TemplatePacks)->all())->toThrow(LogicException::class, 'out of order');

        array_map('unlink', glob("{$dir}/*.php"));
        rmdir($dir);
    });
});
