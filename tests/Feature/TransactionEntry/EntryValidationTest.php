<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

// What is checked when an entry is submitted and again when it is posted (TE-FR-08, TE-AC-03).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->bank = valAccountId('1112');
    $this->sales = valAccountId('4110');

    $this->typeId = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated()->json('data.id');
});

function valAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function valEntry(array $overrides = []): string
{
    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', array_merge([
        'transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'Penjualan',
        'lines' => [['account_id' => test()->bank, 'debit' => '100.00'], ['account_id' => test()->sales, 'credit' => '100.00']],
    ], $overrides))->assertCreated()->json('data.id');
}

function valAct(User $user, string $id, string $action)
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}");
}

function valStatus(string $id): string
{
    return (string) test()->actingAs(test()->preparer)->getJson(test()->url."/entries/{$id}")->json('data.status');
}

describe('refusals leave no journal and the entry as it was (TE-AC-03)', function () {
    it('names the field and the code', function () {
        $cases = [
            'an account that does not exist' => [['lines' => [['account_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'debit' => '100.00'], ['account_id' => $this->sales, 'credit' => '100.00']]], 'ACCOUNT_NOT_POSTABLE', 'lines.0.account_id'],
            'a date no period accepts' => [['accounting_date' => '2030-03-15'], 'PERIOD_NOT_OPEN', 'accounting_date'],
            'lines that do not balance' => [['lines' => [['account_id' => $this->bank, 'debit' => '100.00'], ['account_id' => $this->sales, 'credit' => '90.00']]], 'UNBALANCED_ENTRY', 'lines'],
        ];

        foreach ($cases as $name => [$overrides, $code, $field]) {
            $id = valEntry($overrides);

            valAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonPath('code', $code)->assertJsonValidationErrors([$field]);

            expect(valStatus($id))->toBe('draft', $name)->and(DB::table('gl_journals')->count())->toBe(0);
        }
    });

    it('refuses a foreign line that has no rate', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
        $id = valEntry(['lines' => [
            ['account_id' => $this->bank, 'debit' => '1.00', 'currency' => 'USD'],
            ['account_id' => $this->sales, 'credit' => '1.00', 'currency' => 'USD'],
        ]]);

        valAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
        expect(valStatus($id))->toBe('draft')->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('refuses a missing required dimension', function () {
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->sales}", ['lock_version' => 1, 'dimension_requirements' => ['department' => 'required']])->assertOk();
        $id = valEntry();

        valAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonPath('code', 'DIMENSION_REQUIRED')->assertJsonValidationErrors(['lines.1.dimensions.department']);
        expect(valStatus($id))->toBe('draft');
    });
});

describe('version of the entry', function () {
    it('refuses to submit on a version that was retired after the draft', function () {
        $id = valEntry();

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/1/retire")->assertOk();

        valAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonPath('code', 'TRANSACTION_TYPE_VERSION_RETIRED');
        expect(valStatus($id))->toBe('draft');
    });

    it('checks again when posting, and posts nothing', function () {
        $id = valEntry();
        valAct($this->preparer, $id, 'submit')->assertOk();
        valAct($this->approver, $id, 'approve')->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/1/retire")->assertOk();

        valAct($this->approver, $id, 'post')->assertUnprocessable()->assertJsonPath('code', 'TRANSACTION_TYPE_VERSION_RETIRED');

        expect(valStatus($id))->toBe('approved')->and(DB::table('gl_journals')->count())->toBe(0);
    });
});

describe('structured entries', function () {
    function valStructured(array $fields, array $rules): void
    {
        $versions = test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types/'.test()->typeId.'/versions', ['mode' => 'structured', 'fields' => $fields, 'posting_rules' => $rules, 'allow_rate_input' => true])
            ->assertCreated()->json('data.versions');
        $number = (int) collect($versions)->max('version');

        test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types/'.test()->typeId."/versions/{$number}/publish")->assertOk();
    }

    function valSale(array $values, array $extra = []): string
    {
        return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', array_merge([
            'transaction_type_key' => 'general_journal', 'transaction_type_version' => 2, 'accounting_date' => '2026-03-15', 'description' => 'Penjualan', 'values' => $values,
        ], $extra))->assertCreated()->json('data.id');
    }

    beforeEach(function () {
        valStructured(
            [['name' => 'total', 'type' => 'amount', 'label' => 'Total'], ['name' => 'revenue', 'type' => 'account', 'label' => 'Pendapatan']],
            [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'fixed', 'account_id' => $this->bank], 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'revenue'], 'reuses_field' => true],
            ],
        );
    });

    it('refuses more decimals than the currency has, and an account of another company, together', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        confirmChartOfAccounts($other, $otherCompany);
        $foreign = (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $otherCompany)->where('code', '4110')->value('id');

        $id = valSale(['total' => '100.005', 'revenue' => $foreign]);

        valAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.total', 'values.revenue']);
        expect(valStatus($id))->toBe('draft')->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('takes an amount within the scale of the currency', function () {
        $id = valSale(['total' => '100.00', 'revenue' => $this->sales]);

        valAct($this->preparer, $id, 'submit')->assertOk();
    });

    it('measures the scale in the currency of the entry', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'JPY', 'scale' => 0])->assertCreated();

        $id = valSale(['total' => '100.50', 'revenue' => $this->sales], ['currency' => 'JPY', 'exchange_rate' => '110']);

        valAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.total']);
    });

    it('refuses a currency the company deactivated after the draft', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

        $id = valSale(['total' => '100.00', 'revenue' => $this->sales], ['currency' => 'USD', 'exchange_rate' => '16000']);
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies/USD/deactivate")->assertOk();

        valAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['currency']);
    });
});
