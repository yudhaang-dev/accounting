<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Orchestration\Setup\TemplatePacks;
use App\Support\Company\CompanyScope;

// The cash and receivable accounts take only the subledger as a source, so the activity is posted as such. These tests install copies of the manifests marked as reviewed, which proves the content installs.
beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
    $this->dir = sys_get_temp_dir().'/btp-'.uniqid();
    mkdir($this->dir);

    foreach (glob(app_path('Orchestration/Setup/packs/*.php')) as $file) {
        $pack = require $file;
        $pack['reviewed'] = true;
        file_put_contents("{$this->dir}/".basename($file), '<?php return '.var_export($pack, true).';');
    }

    config(['templates.packs_path' => $this->dir]);
    app()->forgetInstance(TemplatePacks::class);
});

afterEach(function () {
    array_map('unlink', glob("{$this->dir}/*.php"));
    rmdir($this->dir);
});

function btpInstall(string $key)
{
    $pack = app(TemplatePacks::class)->find($key);
    $components = array_column($pack['components'], 'id');

    return test()->actingAs(test()->admin)->postJson(test()->url."/template-packs/{$key}/install", ['components' => $components, 'confirmed' => true]);
}

function btpAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function btpPost(string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: '2026-03-10',
        description: 'Activity',
        source: JournalSource::Subledger,
        lines: [new PostJournalLine(btpAccountId($debit), $amount, '0'), new PostJournalLine(btpAccountId($credit), '0', $amount)],
    ));
}

function btpValues(string $key, string $query): array
{
    $id = collect(test()->actingAs(test()->admin)->getJson(test()->url.'/report-definitions')->json('data'))->firstWhere('key', $key)['id'];
    $data = test()->actingAs(test()->admin)->getJson(test()->url."/report-definitions/{$id}/result?{$query}")->assertOk()->json('data');

    return [$data, collect($data['rows'])->mapWithKeys(fn (array $row): array => [$row['key'] => $row['values']])->all()];
}

describe('packs of a business type (B-11e, ADR-0019)', function () {
    it('lists the released packs of every business type', function () {
        config(['templates.packs_path' => app_path('Orchestration/Setup/packs')]);
        app()->forgetInstance(TemplatePacks::class);

        $keys = array_column($this->actingAs($this->admin)->getJson("{$this->url}/template-packs")->assertOk()->json('data'), 'key');

        expect($keys)->toContain('general', 'retail', 'services', 'contractor', 'manufacturing', 'fnb', 'addon-loans', 'addon-tax', 'addon-inventory', 'addon-project');
    });

    it('marks the add-on packs as additive so a wizard does not offer them to an empty company', function () {
        $packs = collect($this->actingAs($this->admin)->getJson("{$this->url}/template-packs")->assertOk()->json('data'))->pluck('additive', 'key');

        expect($packs['addon-loans'])->toBeTrue()->and($packs['addon-tax'])->toBeTrue()->and($packs['addon-inventory'])->toBeTrue()->and($packs['addon-project'])->toBeTrue()
            ->and($packs['general'])->toBeFalse()->and($packs['retail'])->toBeFalse();
    });

    it('installs the retail pack into an empty company with the variant accounts under the base chart', function () {
        btpInstall('retail')->assertCreated();

        $codes = Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('code')->all();
        $types = TransactionType::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('key')->all();

        expect($codes)->toContain('1131', '5110', '4111', '4112', '5130', '6140')
            ->and($types)->toContain('daily_cash_sale', 'sales_return', 'purchase_return', 'credit_sale', 'general_journal');

        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
    });

    it('installs the services pack into an empty company with the variant accounts under the base chart', function () {
        btpInstall('services')->assertCreated();

        $codes = Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('code')->all();
        $types = TransactionType::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('key')->all();

        expect($codes)->toContain('4120', '4121', '4122', '5220', '6295')
            ->and($types)->toContain('service_invoice', 'service_cash_receipt', 'customer_receipt');

        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
    });

    it('reports the retail variant accounts in the statements, with no unmapped row', function () {
        btpInstall('retail')->assertCreated();
        setUpFiscalCalendar($this->admin, $this->companyId);

        btpPost('1112', '3110', '10000000.00');
        btpPost('1124', '4112', '2000000.00');
        btpPost('1112', '4111', '3000000.00');
        btpPost('5110', '1131', '1500000.00');
        btpPost('5130', '1112', '100000.00');
        btpPost('6140', '1124', '200000.00');

        [$income, $values] = btpValues('income_statement', 'from=2026-03-01&to=2026-03-31');
        expect($values['revenue']['period'])->toBe('5000000.00')
            ->and($values['cost_of_sales']['period'])->toBe('1600000.00')
            ->and($values['operating_expenses']['period'])->toBe('200000.00')
            ->and($values['net_profit']['period'])->toBe('3200000.00')
            ->and($values)->not->toHaveKey('unmapped');

        [$balance, $values] = btpValues('balance_sheet', 'from=2026-03-31&to=2026-03-31');
        expect($balance['balance_checks']['at']['balanced'])->toBeTrue()
            ->and($values)->not->toHaveKey('unmapped');

        [$cash, $values] = btpValues('cash_flow_indirect', 'from=2026-03-01&to=2026-03-31');
        expect($cash['cash_checks']['period']['reconciled'])->toBeTrue()
            ->and($values)->not->toHaveKey('unmapped');
    });

    it('reports the services variant accounts in the statements, with no unmapped row', function () {
        btpInstall('services')->assertCreated();
        setUpFiscalCalendar($this->admin, $this->companyId);

        btpPost('1112', '3110', '10000000.00');
        btpPost('1121', '4121', '4000000.00');
        btpPost('1125', '4122', '1000000.00');
        btpPost('5220', '1112', '700000.00');
        btpPost('6295', '1112', '50000.00');

        [$income, $values] = btpValues('income_statement', 'from=2026-03-01&to=2026-03-31');
        expect($values['revenue']['period'])->toBe('5000000.00')
            ->and($values['net_profit']['period'])->toBe('4250000.00')
            ->and($values)->not->toHaveKey('unmapped');

        [$balance, $values] = btpValues('balance_sheet', 'from=2026-03-31&to=2026-03-31');
        expect($balance['balance_checks']['at']['balanced'])->toBeTrue()
            ->and($values)->not->toHaveKey('unmapped');

        [$cash, $values] = btpValues('cash_flow_indirect', 'from=2026-03-01&to=2026-03-31');
        expect($cash['cash_checks']['period']['reconciled'])->toBeTrue()
            ->and($values)->not->toHaveKey('unmapped');
    });

    // Each pack: revenue on a variant account, a cost on another, and a balance sheet that still balances.
    $scenarios = [
        'contractor' => ['types' => ['progress_invoice', 'project_cost_cash'], 'posts' => [['1112', '3110', '10000000.00'], ['1121', '4124', '6000000.00'], ['1124', '4124', '1000000.00'], ['5260', '1112', '2500000.00'], ['5270', '1112', '500000.00']], 'revenue' => '7000000.00', 'profit' => '4000000.00'],
        'manufacturing' => ['types' => ['production_journal', 'factory_cost_cash'], 'posts' => [['1112', '3110', '10000000.00'], ['1133', '1112', '3000000.00'], ['5150', '1133', '2000000.00'], ['5160', '1112', '500000.00'], ['1112', '4114', '4000000.00']], 'revenue' => '4000000.00', 'profit' => '1500000.00'],
        'fnb' => ['types' => ['fnb_daily_sales', 'delivery_platform_sale'], 'posts' => [['1112', '3110', '10000000.00'], ['1112', '4116', '2000000.00'], ['1124', '4118', '1000000.00'], ['5150', '1112', '900000.00'], ['6296', '1112', '100000.00'], ['6140', '1124', '150000.00']], 'revenue' => '3000000.00', 'profit' => '1850000.00'],
    ];

    foreach ($scenarios as $key => $scenario) {
        it("installs the {$key} pack, activates the company, and reports its variant accounts with no unmapped row", function () use ($key, $scenario) {
            btpInstall($key)->assertCreated();

            $types = TransactionType::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('key')->all();
            expect($types)->toContain(...$scenario['types']);

            setUpFiscalCalendar($this->admin, $this->companyId);
            $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();

            foreach ($scenario['posts'] as [$debit, $credit, $amount]) {
                btpPost($debit, $credit, $amount);
            }

            [, $values] = btpValues('income_statement', 'from=2026-03-01&to=2026-03-31');
            expect($values['revenue']['period'])->toBe($scenario['revenue'])
                ->and($values['net_profit']['period'])->toBe($scenario['profit'])
                ->and($values)->not->toHaveKey('unmapped');

            [$balance, $values] = btpValues('balance_sheet', 'from=2026-03-31&to=2026-03-31');
            expect($balance['balance_checks']['at']['balanced'])->toBeTrue()->and($values)->not->toHaveKey('unmapped');

            [$cash, $values] = btpValues('cash_flow_indirect', 'from=2026-03-01&to=2026-03-31');
            expect($cash['cash_checks']['period']['reconciled'])->toBeTrue()->and($values)->not->toHaveKey('unmapped');
        });
    }

    // An add-on goes on top of a chart the company already has and only adds to it.
    $addons = [
        'addon-loans' => ['codes' => ['1171', '1172', '2161'], 'types' => ['employee_advance', 'party_loan'], 'posts' => [['1171', '1112', '500000.00'], ['1112', '2161', '2000000.00']]],
        'addon-tax' => ['codes' => ['1143', '2154', '2155', '7330'], 'types' => ['tax_accrual', 'tax_payment'], 'posts' => [['7330', '2154', '100000.00'], ['2154', '1112', '100000.00']]],
        'addon-inventory' => ['codes' => ['1138', '5125'], 'types' => ['stock_count_adjustment', 'inventory_write_down'], 'posts' => [['5125', '1138', '300000.00']]],
        'addon-project' => ['codes' => ['1137', '2143', '4126'], 'types' => ['progress_invoice', 'project_cost_cash'], 'posts' => [['1112', '2143', '1000000.00'], ['1121', '4126', '800000.00']]],
    ];

    foreach ($addons as $key => $addon) {
        it("adds the {$key} accounts and types to an existing chart, skips them on a second install, and keeps the statements mapped", function () use ($key, $addon) {
            btpInstall('general')->assertCreated();
            setUpFiscalCalendar($this->admin, $this->companyId);
            btpPost('1112', '3110', '10000000.00');

            $result = btpInstall($key)->assertCreated()->json('data.components');
            $chart = collect($result)->firstWhere('id', 'chart_of_accounts');
            $codes = Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('code')->all();
            $types = TransactionType::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('key')->all();

            expect($chart['created'])->toBe($addon['codes'])->and($codes)->toContain(...$addon['codes'])->and($types)->toContain(...$addon['types']);

            $again = collect(btpInstall($key)->assertCreated()->json('data.components'))->firstWhere('id', 'chart_of_accounts');
            expect($again['created'])->toBe([])->and($again['skipped'])->toBe($addon['codes']);

            foreach ($addon['posts'] as [$debit, $credit, $amount]) {
                btpPost($debit, $credit, $amount);
            }

            [, $values] = btpValues('income_statement', 'from=2026-03-01&to=2026-03-31');
            expect($values)->not->toHaveKey('unmapped');
            [$balance, $values] = btpValues('balance_sheet', 'from=2026-03-31&to=2026-03-31');
            expect($balance['balance_checks']['at']['balanced'])->toBeTrue()->and($values)->not->toHaveKey('unmapped');
            [$cash, $values] = btpValues('cash_flow_indirect', 'from=2026-03-01&to=2026-03-31');
            expect($cash['cash_checks']['period']['reconciled'])->toBeTrue()->and($values)->not->toHaveKey('unmapped');
        });
    }

    it('reports an add-on account whose code the company uses for something else as a conflict and never overwrites it', function () {
        btpInstall('general')->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts", ['code' => '2154', 'name' => 'Utang Lain Milik Perusahaan', 'class' => 'liability', 'parent_id' => btpAccountId('2150')])->assertCreated();

        $components = array_column(app(TemplatePacks::class)->find('addon-tax')['components'], 'id');
        $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/addon-tax/install", ['components' => $components, 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('exclude.chart_of_accounts');
        expect(Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '7330')->exists())->toBeFalse();

        $result = $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/addon-tax/install", ['components' => $components, 'confirmed' => true, 'exclude' => ['chart_of_accounts' => ['2154']]])->assertCreated()->json('data.components');
        $chart = collect($result)->firstWhere('id', 'chart_of_accounts');

        expect($chart['skipped'])->toBe(['2154'])->and($chart['created'])->toContain('7330')
            ->and(Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '2154')->value('name'))->toBe('Utang Lain Milik Perusahaan');
    });

    it('keeps every account of the variants in CHART-OF-ACCOUNTS.md', function () {
        $doc = file_get_contents(base_path('.docs/CHART-OF-ACCOUNTS.md'));

        foreach (['retail-id' => 'Dagang atau ritel', 'services-id' => 'Jasa', 'contractor-id' => 'Kontraktor atau proyek', 'manufacturing-id' => 'Manufaktur ringan', 'fnb-id' => 'Makanan dan minuman', 'loans-addon' => 'Modul tambahan — Kasbon dan pinjaman', 'tax-addon' => 'Modul tambahan — Pajak', 'inventory-addon' => 'Modul tambahan — Persediaan dan HPP', 'project-addon' => 'Modul tambahan — Proyek'] as $key => $heading) {
            $section = explode('### ', explode("### {$heading} (`{$key}`)", $doc, 2)[1], 2)[0];
            preg_match_all('/^\|\s*(\d{4})\s*\|\s*([^|]+?)\s*\|\s*`(\w+)`\s*\|\s*([DK])\s*\|\s*(\d{4})\s*\|/m', $section, $rows, PREG_SET_ORDER);
            $template = require app_path("Modules/GeneralLedger/Database/templates/{$key}.php");

            expect(array_map(fn (array $row): array => [$row[1], $row[2], $row[3], $row[4] === 'D' ? 'debit' : 'credit', $row[5]], $rows))
                ->toBe(array_map(fn (array $account): array => [$account['code'], $account['name'], $account['class'], $account['normal'], $account['parent']], $template['accounts']));
        }
    });
});

describe('the simple profile (B-11i, ADR-0019 item 6)', function () {
    it('offers the profile on every business type pack and on no other', function () {
        $profiles = collect($this->actingAs($this->admin)->getJson("{$this->url}/template-packs")->assertOk()->json('data'))->pluck('profiles', 'key');

        foreach (['retail', 'services', 'contractor', 'manufacturing', 'fnb'] as $key) {
            expect($profiles[$key])->toBe(['simple']);
        }

        expect($profiles['general'])->toBe([])->and($profiles['addon-tax'])->toBe([]);
    });

    it('describes only the components of the profile, without the free-form journal type', function () {
        $data = $this->actingAs($this->admin)->getJson("{$this->url}/template-packs/retail?profile=simple")->assertOk()->json('data');
        $ids = array_column($data['components'], 'id');

        expect($ids)->toContain('chart_of_accounts', 'transaction_types.daily-operations', 'transaction_types.retail-operations', 'report_definitions')
            ->and($ids)->not->toContain('transaction_types.general');

        $this->actingAs($this->admin)->getJson("{$this->url}/template-packs/retail?profile=nope")->assertNotFound();
    });

    it('previews the chart without the optional accounts the install leaves out, so the count is what gets created', function () {
        $items = fn (string $query) => collect($this->actingAs($this->admin)->getJson("{$this->url}/template-packs/retail{$query}")->json('data.components'))->firstWhere('id', 'chart_of_accounts')['items'];
        $full = $items('');
        $preview = $items('?profile=simple');
        $left = collect($full)->where('optional', true)->pluck('key')->diff(['2160', '2170'])->all();

        expect($left)->not->toBe([])
            ->and(array_column($preview, 'key'))->not->toContain(...$left)
            ->and(array_column($preview, 'key'))->toContain('2160', '2170')
            ->and(count($preview))->toBe(count($full) - count($left));
    });

    it('installs without the optional accounts and still activates and reports with no unmapped row', function () {
        $full = collect($this->actingAs($this->admin)->getJson("{$this->url}/template-packs/retail")->json('data.components'))->firstWhere('id', 'chart_of_accounts')['items'];
        $optional = collect($full)->where('optional', true)->pluck('key')->all();
        expect($optional)->not->toBe([]);

        $result = $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/retail/install", ['profile' => 'simple', 'confirmed' => true])->assertCreated()->json('data');
        $codes = Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('code')->all();
        $types = TransactionType::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('key')->all();

        // 2160 and 2170 are optional but named by the cash flow definition, so the profile keeps them.
        expect(array_values(array_intersect($optional, $codes)))->toBe(['2160', '2170'])
            ->and(array_column($result['components'], 'id'))->not->toContain('transaction_types.general')
            ->and($types)->toContain('daily_cash_sale')->and($types)->not->toContain('general_journal');

        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();

        [, $values] = btpValues('income_statement', 'from=2026-03-01&to=2026-03-31');
        expect($values)->not->toHaveKey('unmapped');
    });

    it('installs the simple profile of every business type pack into an empty company and activates it', function (string $key) {
        $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/{$key}/install", ['profile' => 'simple', 'confirmed' => true])->assertCreated();

        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();

        foreach (['income_statement' => 'from=2026-03-01&to=2026-03-31', 'balance_sheet' => 'from=2026-03-31&to=2026-03-31', 'cash_flow_indirect' => 'from=2026-03-01&to=2026-03-31'] as $report => $query) {
            expect(btpValues($report, $query)[1])->not->toHaveKey('unmapped');
        }
    })->with(['retail', 'services', 'contractor', 'manufacturing', 'fnb']);

    it('refuses a component outside the profile and an unknown profile, creating nothing', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/retail/install", ['profile' => 'simple', 'components' => ['chart_of_accounts', 'transaction_types.general'], 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('components.1');
        $this->actingAs($this->admin)->postJson("{$this->url}/template-packs/retail/install", ['profile' => 'nope', 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('profile');

        expect(Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->count())->toBe(0);
    });
});
