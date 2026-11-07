<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

// Posting rules of structured versions and the entries on them (TE-FR-05, TE-AC-02, TE-AC-06).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->bank = ruleAccountId('1112');
    $this->sales = ruleAccountId('4110');
    $this->gain = ruleAccountId('4130');
    $this->loss = ruleAccountId('6220');

    $this->typeId = $this->actingAs($this->admin)
        ->postJson("{$this->url}/transaction-types", ['key' => 'cash_sale', 'label' => 'Penjualan Tunai'])
        ->assertCreated()->json('data.id');
});

function ruleAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function ruleDepartmentValueId(string $code = 'SALES'): string
{
    $type = collect(test()->actingAs(test()->admin)->getJson(test()->url.'/dimension-types')->json('data'))->firstWhere('key', 'department')['id'];

    return (string) test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-values', ['dimension_type_id' => $type, 'code' => $code, 'name' => ucfirst(strtolower($code))])
        ->assertCreated()->json('data.id');
}

function fixed(string $accountId): array
{
    return ['source' => 'fixed', 'account_id' => $accountId];
}

/** The fields of the usual sale: a total, an optional discount, a department and a revenue account. */
function saleFields(): array
{
    return [
        ['name' => 'total', 'type' => 'amount', 'label' => 'Total'],
        ['name' => 'discount', 'type' => 'amount', 'label' => 'Diskon', 'required' => false],
        ['name' => 'revenue', 'type' => 'account', 'label' => 'Pendapatan', 'account_ids' => [test()->sales, test()->gain]],
        ['name' => 'dept', 'type' => 'dimension', 'label' => 'Departemen', 'dimension_type' => 'department', 'required' => false],
    ];
}

function saleRules(): array
{
    return [
        ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => fixed(test()->bank), 'apply_dimensions' => true],
        ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'discount', 'account' => fixed(test()->sales)],
        ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'revenue'], 'apply_dimensions' => true, 'reuses_field' => true],
    ];
}

function ruleVersionBody(?array $fields = null, ?array $rules = null): array
{
    $rules ??= saleRules();
    if ($rules !== []) {
        $rules[0]['reuses_field'] ??= true;
    }

    return ['mode' => 'structured', 'fields' => $fields ?? saleFields(), 'posting_rules' => $rules];
}

function publishedVersion(?array $body = null): int
{
    $versions = test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types/'.test()->typeId.'/versions', $body ?? ruleVersionBody())->assertCreated()->json('data.versions');
    $number = (int) collect($versions)->max('version');

    test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types/'.test()->typeId."/versions/{$number}/publish")->assertOk();

    return $number;
}

function saleEntry(array $values = [], array $overrides = []): array
{
    return array_merge([
        'transaction_type_key' => 'cash_sale',
        'accounting_date' => '2026-03-15',
        'description' => 'Penjualan tunai',
        'values' => $values + ['total' => '1000.00', 'revenue' => test()->sales],
    ], $overrides);
}

function createSale(array $values = [], array $overrides = [])
{
    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', saleEntry($values, $overrides));
}

function journalLines(string $entryId): array
{
    $journalId = DB::table('te_entries')->where('id', $entryId)->value('journal_id');

    return DB::table('gl_journal_lines')->where('journal_id', $journalId)->orderBy('line_number')->get()->map(fn ($line) => [
        'account_id' => $line->account_id, 'debit' => (float) $line->debit, 'credit' => (float) $line->credit,
    ])->all();
}

describe('rules of a version (TE-FR-05)', function () {
    it('stores the rules in order and returns them with the version', function () {
        // Version 1 is the free-lines one every type starts with.
        $index = publishedVersion() - 1;

        $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types/{$this->typeId}")
            ->assertJsonPath("data.versions.{$index}.posting_rules.0.side", 'debit')
            ->assertJsonPath("data.versions.{$index}.posting_rules.0.apply_dimensions", true)
            ->assertJsonPath("data.versions.{$index}.posting_rules.1.apply_dimensions", false)
            ->assertJsonPath("data.versions.{$index}.posting_rules.2.account.field", 'revenue');
    });

    it('refuses rules that point at nothing the version or the company has', function () {
        $post = fn (array $rules) => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", ruleVersionBody(null, $rules));
        $line = ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => fixed($this->bank), 'reuses_field' => true];

        $post([array_merge($line, ['amount_field' => 'nope'])])->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.amount_field']);
        $post([array_merge($line, ['amount_field' => 'dept'])])->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.amount_field']);
        $post([array_merge($line, ['side' => null])])->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.side']);
        $post([array_merge($line, ['account' => fixed('01JZZZZZZZZZZZZZZZZZZZZZZZ')])])->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account.account_id']);
        $post([array_merge($line, ['account' => ['source' => 'field', 'field' => 'total']])])->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account.field']);
        $post([array_merge($line, ['account' => null])])->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.account']);
        $post([$line, ['type' => 'fx_difference'], ['type' => 'fx_difference']])->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.2.type']);
    });

    it('does not publish a version whose rules share a field without saying so (TE-AC-06)', function () {
        $shared = ruleVersionBody(null, [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => fixed($this->bank)],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => fixed($this->sales), 'reuses_field' => true],
        ]);
        $shared['posting_rules'][0]['reuses_field'] = false;

        $number = (int) collect($this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", $shared)->assertCreated()->json('data.versions'))->max('version');

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.reuses_field']);

        // Once both rules say so, the version publishes.
        $shared['posting_rules'][0]['reuses_field'] = true;
        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}", $shared)->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/publish")->assertOk();
    });

    it('treats an account field shared by two rules like an amount field', function () {
        $body = ruleVersionBody(null, [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'revenue']],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'discount', 'account' => ['source' => 'field', 'field' => 'revenue']],
        ]);
        unset($body['posting_rules'][0]['reuses_field']);
        $number = (int) collect($this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", $body)->assertCreated()->json('data.versions'))->max('version');

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.0.reuses_field', 'posting_rules.1.reuses_field']);
    });

    it('does not publish a structured version without rules, and gives a free-lines version none', function () {
        $number = (int) collect($this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", ruleVersionBody(null, []))->assertCreated()->json('data.versions'))->max('version');
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/publish")->assertUnprocessable()->assertJsonValidationErrors(['posting_rules']);

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", ['mode' => 'free_lines', 'fields' => [], 'posting_rules' => saleRules()])
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_rules']);
    });

    it('keeps the rules of a published version', function () {
        $number = publishedVersion();

        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}", ruleVersionBody())->assertConflict();
    });
});

describe('entries on a structured version (TE-AC-02)', function () {
    it('builds the lines from the values by the rules, applies dimensions only where asked, and skips zero lines', function () {
        publishedVersion();
        $sales = ruleDepartmentValueId();

        $response = createSale(['dept' => $sales, 'discount' => '0.00'])->assertCreated()
            ->assertJsonPath('data.values.total', '1000.00')
            ->assertJsonPath('data.status', 'draft');

        $lines = $response->json('data.lines');

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toMatchArray(['account_id' => $this->bank, 'debit' => '1000.00', 'credit' => '0', 'dimensions' => ['department' => $sales]])
            ->and($lines[1])->toMatchArray(['account_id' => $this->sales, 'debit' => '0', 'credit' => '1000.00', 'dimensions' => ['department' => $sales]]);

        // A discount is a line of its own, without the department of the entry.
        $with = createSale(['dept' => $sales, 'discount' => '50.00', 'total' => '1000.00'])->assertCreated()->json('data.lines');

        expect($with)->toHaveCount(3)
            ->and($with[1])->toMatchArray(['account_id' => $this->sales, 'debit' => '50.00', 'dimensions' => []]);
    });

    it('posts the generated lines through the ledger, and values nobody computed stay as given', function () {
        publishedVersion();

        $id = createSale(['total' => '1250.50'])->assertCreated()->json('data.id');
        ruleAction($this->preparer, $id, 'submit')->assertOk();
        ruleAction($this->approver, $id, 'approve')->assertOk();
        ruleAction($this->approver, $id, 'post')->assertOk()->assertJsonPath('data.status', 'posted');

        expect(journalLines($id))->toBe([
            ['account_id' => $this->bank, 'debit' => 1250.5, 'credit' => 0.0],
            ['account_id' => $this->sales, 'debit' => 0.0, 'credit' => 1250.5],
        ]);
    });

    it('does not submit an entry that nets to nothing, nor one whose lines do not balance', function () {
        publishedVersion();

        $id = createSale(['total' => '0'])->assertCreated()->assertJsonPath('data.lines', [])->json('data.id');
        ruleAction($this->preparer, $id, 'submit')->assertUnprocessable();

        // The discount is debited without a credit to match it.
        $unbalanced = createSale(['discount' => '10.00'])->assertCreated()->json('data.id');
        ruleAction($this->preparer, $unbalanced, 'submit')->assertUnprocessable();
        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$unbalanced}")->assertJsonPath('data.status', 'draft');
    });

    it('refuses values that do not fit the schema, with an error per field', function () {
        publishedVersion();

        createSale(['total' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['values.total']);
        createSale(['total' => 1000])->assertUnprocessable()->assertJsonValidationErrors(['values.total']);
        createSale(['revenue' => $this->bank])->assertUnprocessable()->assertJsonValidationErrors(['values.revenue']);
        createSale(['extra' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['values.extra']);

        $missing = saleEntry();
        unset($missing['values']['total']);
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", $missing)->assertUnprocessable()->assertJsonValidationErrors(['values.total']);
    });

    it('takes values for a structured version and lines for a free-lines one', function () {
        publishedVersion();

        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", saleEntry([], ['lines' => [['account_id' => $this->bank, 'debit' => '1']]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", saleEntry([], ['values' => null]))->assertUnprocessable();

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", ['transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'x', 'values' => ['total' => '1']])
            ->assertUnprocessable()->assertJsonValidationErrors(['values']);
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", ['transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    });

    it('rebuilds the lines when a draft is edited', function () {
        publishedVersion();
        $entry = createSale()->assertCreated();

        $this->actingAs($this->preparer)->patchJson("{$this->url}/entries/{$entry->json('data.id')}", [
            'lock_version' => $entry->json('data.lock_version'),
            'accounting_date' => '2026-03-15',
            'description' => 'Revisi',
            'values' => ['total' => '300.00', 'revenue' => $this->sales],
        ])->assertOk()->assertJsonPath('data.lines.0.debit', '300.00')->assertJsonPath('data.values.total', '300.00');
    });

    it('leaves entries on an earlier version as they were', function () {
        $first = publishedVersion();
        $id = createSale()->assertCreated()->assertJsonPath('data.transaction_type_version', $first)->json('data.id');

        publishedVersion(ruleVersionBody(null, [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => fixed($this->sales), 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => fixed($this->bank), 'reuses_field' => true],
        ]));

        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.lines.0.account_id', $this->bank);
        createSale()->assertCreated()->assertJsonPath('data.lines.0.account_id', $this->sales);
    });
});

describe('exchange difference line (TE-FR-05)', function () {
    function transferVersion(): int
    {
        return publishedVersion([
            'mode' => 'structured',
            'fields' => [
                ['name' => 'received', 'type' => 'amount', 'label' => 'Diterima'],
                ['name' => 'paid', 'type' => 'amount', 'label' => 'Dibayar'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'received', 'account' => fixed(test()->sales)],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'paid', 'account' => fixed(test()->bank)],
                ['type' => 'fx_difference'],
            ],
        ]);
    }

    function transfer(string $received, string $paid)
    {
        return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', [
            'transaction_type_key' => 'cash_sale', 'accounting_date' => '2026-03-15', 'description' => 'Transfer',
            'values' => ['received' => $received, 'paid' => $paid],
        ]);
    }

    it('takes the difference to the realized gain account when the debits exceed the credits', function () {
        transferVersion();
        mapRoleAccount('fx_realized_gain', $this->gain);
        mapRoleAccount('fx_realized_loss', $this->loss);

        $id = transfer('1010.00', '1000.00')->assertCreated()->assertJsonCount(3, 'data.lines')
            ->assertJsonPath('data.lines.2.account_id', $this->gain)->assertJsonPath('data.lines.2.credit', '10.00')->json('data.id');

        ruleAction($this->preparer, $id, 'submit')->assertOk();
        ruleAction($this->approver, $id, 'approve')->assertOk();
        ruleAction($this->approver, $id, 'post')->assertOk();

        expect(array_sum(array_column(journalLines($id), 'debit')))->toBe(array_sum(array_column(journalLines($id), 'credit')))->toBe(1010.0);
    });

    it('takes it to the realized loss account when the credits exceed the debits', function () {
        transferVersion();
        mapRoleAccount('fx_realized_gain', $this->gain);
        mapRoleAccount('fx_realized_loss', $this->loss);

        transfer('990.00', '1000.00')->assertCreated()
            ->assertJsonPath('data.lines.2.account_id', $this->loss)->assertJsonPath('data.lines.2.debit', '10.00')->assertJsonPath('data.lines.2.credit', '0');
    });

    it('adds no line when nothing is left over, and needs no role then', function () {
        transferVersion();

        transfer('1000.00', '1000.00')->assertCreated()->assertJsonCount(2, 'data.lines');
    });

    it('asks for the role mapping when there is a difference to carry', function () {
        transferVersion();

        transfer('1010.00', '1000.00')->assertUnprocessable()->assertJsonPath('code', 'MAPPING_REQUIRED');
    });

    function mapRoleAccount(string $role, string $accountId): void
    {
        test()->actingAs(test()->admin)->putJson(test()->url."/account-roles/{$role}", ['lock_version' => 0, 'account_id' => $accountId])->assertOk();
    }
});

function ruleAction(User $user, string $id, string $action)
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}");
}

describe('account classes of an account field (TE-FR-04, TE-AC-11)', function () {
    function incomeOnlyFields(): array
    {
        $fields = saleFields();
        $fields[2] = ['name' => 'revenue', 'type' => 'account', 'label' => 'Pendapatan', 'account_classes' => ['income']];

        return $fields;
    }

    it('publishes the classes in the schema and refuses an unknown class or a non-account field', function () {
        $post = fn (array $fields) => $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", ruleVersionBody($fields));

        $fields = saleFields();
        $fields[2]['account_classes'] = ['revenue'];
        $post($fields)->assertUnprocessable()->assertJsonValidationErrors(['fields.2.account_classes.0']);

        $fields = saleFields();
        $fields[0]['account_classes'] = ['income'];
        $post($fields)->assertUnprocessable()->assertJsonValidationErrors(['fields.0.account_classes']);

        $number = publishedVersion(ruleVersionBody(incomeOnlyFields()));
        $schema = $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types/{$this->typeId}/versions/{$number}/schema")->json('data');

        expect($schema['properties']['revenue']['x-account-classes'])->toBe(['income']);
    });

    it('refuses an entry whose account is of another class, and takes one of the class', function () {
        publishedVersion(ruleVersionBody(incomeOnlyFields()));

        $rejected = createSale(['revenue' => $this->bank]);
        if ($rejected->status() === 201) {
            $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$rejected->json('data.id')}/submit")->assertUnprocessable()->assertJsonValidationErrors(['values.revenue']);
        } else {
            $rejected->assertUnprocessable()->assertJsonValidationErrors(['values.revenue']);
        }

        $accepted = createSale(['revenue' => $this->sales])->assertCreated();
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$accepted->json('data.id')}/submit")->assertOk();
    });
});
