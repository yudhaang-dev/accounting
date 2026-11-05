<?php

use App\Models\User;
use App\Modules\GeneralLedger\Application\ChartTemplates;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
    $this->dir = sys_get_temp_dir().'/cv-'.uniqid();
    mkdir("{$this->dir}/charts", 0777, true);
    mkdir("{$this->dir}/packs");
    config(['templates.extra_chart_paths' => ["{$this->dir}/charts"], 'templates.packs_path' => "{$this->dir}/packs"]);
});

afterEach(function () {
    array_map('unlink', [...glob("{$this->dir}/charts/*.php"), ...glob("{$this->dir}/packs/*.php")]);
    rmdir("{$this->dir}/charts");
    rmdir("{$this->dir}/packs");
    rmdir($this->dir);
});

function cvWrite(string $path, array $data): void
{
    file_put_contents($path, '<?php return '.var_export($data, true).';');
}

function cvAccount(string $code, string $name, string $class, ?string $parent): array
{
    return ['code' => $code, 'name' => $name, 'class' => $class, 'normal' => null, 'parent' => $parent, 'optional' => false, 'note' => null];
}

function cvCount(): int
{
    return Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->count();
}

function cvAddonPack(string $dir, array $accounts): void
{
    cvWrite("{$dir}/charts/addon.php", ['key' => 'addon-test', 'name' => 'Add-on', 'version' => 1, 'additive' => true, 'accounts' => $accounts]);
    cvWrite("{$dir}/packs/addon.php", ['key' => 'addon', 'name' => 'Addon', 'description' => '', 'version' => 1, 'reviewed' => true, 'components' => [['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'addon-test']]]);
}

describe('chart variants and add-ons (ADR-0019)', function () {
    it('lists a variant as a chart of its own with the base accounts repeated once, and never lists an add-on', function () {
        cvWrite("{$this->dir}/charts/variant.php", ['key' => 'retail-test', 'name' => 'Retail', 'version' => 1, 'extends' => 'general-id', 'accounts' => [cvAccount('1133', 'Barang Konsinyasi', 'asset', '1130')]]);
        cvWrite("{$this->dir}/charts/addon.php", ['key' => 'addon-test', 'name' => 'Add-on', 'version' => 1, 'additive' => true, 'accounts' => [cvAccount('1134', 'Barang Transit', 'asset', '1130')]]);

        $keys = array_column($this->actingAs($this->admin)->getJson("{$this->url}/account-templates")->assertOk()->json('data'), 'key');
        $base = count($this->actingAs($this->admin)->getJson("{$this->url}/account-templates/general-id")->json('data.accounts'));
        $variant = $this->actingAs($this->admin)->getJson("{$this->url}/account-templates/retail-test")->assertOk()->json('data.accounts');

        expect($keys)->toContain('general-id', 'retail-test')->not->toContain('addon-test')
            ->and($variant)->toHaveCount($base + 1);
        $this->actingAs($this->admin)->getJson("{$this->url}/account-templates/addon-test")->assertNotFound();
    });

    it('refuses a template that extends an unknown one or repeats a code', function () {
        cvWrite("{$this->dir}/charts/bad.php", ['key' => 'bad', 'name' => 'Bad', 'version' => 1, 'extends' => 'nowhere', 'accounts' => []]);
        expect(fn () => (new ChartTemplates)->all())->toThrow(LogicException::class, 'unknown template');

        cvWrite("{$this->dir}/charts/bad.php", ['key' => 'bad', 'name' => 'Bad', 'version' => 1, 'extends' => 'general-id', 'accounts' => [cvAccount('1111', 'Dup', 'asset', '1110')]]);
        expect(fn () => (new ChartTemplates)->all())->toThrow(LogicException::class, 'repeats an account code');
    });

    it('adds the accounts of an add-on to an existing chart, reports a conflict, and never overwrites', function () {
        cvAddonPack($this->dir, [
            cvAccount('1133', 'Barang Konsinyasi', 'asset', '1130'),
            cvAccount('1131', 'Barang Lain', 'asset', '1130'),
            cvAccount('1134', 'Barang Transit', 'asset', '1133'),
        ]);
        confirmChartOfAccounts($this->admin, $this->companyId);
        $count = cvCount();

        $items = collect($this->actingAs($this->admin)->getJson("{$this->url}/template-packs/addon")->assertOk()->json('data.components.0.items'))->keyBy('key');
        expect($items['1133']['exists'])->toBeFalse()->and($items['1133']['conflict'])->toBeNull()
            ->and($items['1131']['conflict'])->not->toBeNull();

        $install = fn (array $exclude) => $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/addon/install", ['confirmed' => true, 'components' => ['chart_of_accounts']] + ($exclude === [] ? [] : ['exclude' => ['chart_of_accounts' => $exclude]]));

        $install([])->assertUnprocessable()->assertJsonValidationErrors('exclude.chart_of_accounts');
        expect(cvCount())->toBe($count);

        $result = $install(['1131'])->assertCreated()->json('data.components.0');
        expect($result['created'])->toBe(['1133', '1134'])->and($result['skipped'])->toBe(['1131'])
            ->and(cvCount())->toBe($count + 2);

        $account = fn (string $code) => Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', $code)->firstOrFail();
        expect($account('1134')->parent_id)->toBe($account('1133')->id);

        $install(['1131'])->assertCreated();
        expect(cvCount())->toBe($count + 2);
    });

    it('fails an add-on whose parent the company does not have, and creates nothing', function () {
        cvAddonPack($this->dir, [cvAccount('1133', 'Barang Konsinyasi', 'asset', '1130'), cvAccount('1190', 'Lain', 'asset', '1180')]);
        confirmChartOfAccounts($this->admin, $this->companyId);
        $count = cvCount();

        $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/addon/install", ['confirmed' => true, 'components' => ['chart_of_accounts']])
            ->assertUnprocessable()->assertJsonValidationErrors('accounts');

        expect(cvCount())->toBe($count);
    });
});
