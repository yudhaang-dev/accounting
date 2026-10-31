<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');
});

function templateTypeKeys(): array
{
    return TransactionType::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->orderBy('key')->pluck('key')->all();
}

function importTemplate(array $body, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/transaction-types/import-template', $body);
}

describe('transaction type templates (TE-FR-01, X-AC-03)', function () {
    it('lists the templates and shows the types of one for review without creating anything', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/transaction-type-templates")
            ->assertOk()->assertJsonPath('data.0.key', 'daily-operations')->assertJsonPath('data.0.type_count', 13)
            ->assertJsonPath('data.1.key', 'general')->assertJsonPath('data.1.type_count', 4);

        $this->actingAs($this->admin)->getJson("{$this->url}/transaction-type-templates/general")
            ->assertOk()
            ->assertJsonPath('data.types.0.key', 'general_journal')
            ->assertJsonPath('data.types.2.mode', 'structured')
            ->assertJsonPath('data.types.2.posting_rules.0.account.source', 'field');

        $this->actingAs($this->admin)->getJson("{$this->url}/transaction-type-templates/unknown")->assertNotFound();
        expect(templateTypeKeys())->toBe([]);
    });

    it('creates nothing until the template is confirmed', function () {
        importTemplate(['template' => 'general', 'keys' => ['general_journal']])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        importTemplate(['template' => 'general', 'confirmed' => false, 'keys' => ['general_journal']])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        importTemplate(['template' => 'general', 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('keys');

        expect(templateTypeKeys())->toBe([]);
    });

    it('creates only the picked types, each with a published first version', function () {
        importTemplate(['template' => 'general', 'confirmed' => true, 'keys' => ['general_journal', 'payroll_summary']])
            ->assertCreated()->assertJsonPath('data.created', ['general_journal', 'payroll_summary']);

        expect(templateTypeKeys())->toBe(['general_journal', 'payroll_summary']);

        $types = collect($this->actingAs($this->admin)->getJson("{$this->url}/transaction-types")->json('data'))->keyBy('key');

        expect($types['general_journal']['versions'])->toHaveCount(1)
            ->and($types['general_journal']['versions'][0])->toMatchArray(['version' => 1, 'mode' => 'free_lines', 'status' => 'published'])
            ->and($types['payroll_summary']['versions'][0])->toMatchArray(['version' => 1, 'mode' => 'structured', 'status' => 'published'])
            ->and($types['payroll_summary']['label'])->toBe('Ringkasan Payroll');
    });

    it('refuses an unknown template or type, and is all-or-nothing when a key is already used', function () {
        importTemplate(['template' => 'nope', 'confirmed' => true, 'keys' => ['general_journal']])->assertUnprocessable()->assertJsonValidationErrors('template');
        importTemplate(['template' => 'general', 'confirmed' => true, 'keys' => ['general_journal', 'unknown_type']])->assertUnprocessable()->assertJsonValidationErrors('keys.1');

        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'adjustment', 'label' => 'Penyesuaian saya'])->assertCreated();

        importTemplate(['template' => 'general', 'confirmed' => true, 'keys' => ['general_journal', 'adjustment']])->assertUnprocessable()->assertJsonValidationErrors('keys');

        expect(templateTypeKeys())->toBe(['adjustment']);
    });

    it('is managed with policies.manage only', function () {
        $this->actingAs($this->preparer)->getJson("{$this->url}/transaction-type-templates")->assertForbidden();
        importTemplate(['template' => 'general', 'confirmed' => true, 'keys' => ['general_journal']], $this->preparer)->assertForbidden();
    });

    it('records the import in the audit log', function () {
        importTemplate(['template' => 'general', 'confirmed' => true, 'keys' => ['adjustment']])->assertCreated();

        expect(DB::table('pf_audit_log')->where('event', 'transaction_types.template_imported')->count())->toBe(1)
            ->and(DB::table('pf_audit_log')->where('event', 'transaction_type.created')->count())->toBe(1);
    });

    it('gives types an entry can be made and posted with', function () {
        importTemplate(['template' => 'general', 'confirmed' => true, 'keys' => ['general_journal', 'payroll_summary']])->assertCreated();

        $account = fn (string $code) => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', $code)->value('id');

        $free = $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal',
            'accounting_date' => '2026-03-15',
            'description' => 'Jurnal umum',
            'lines' => [['account_id' => $account('1112'), 'debit' => '500.00'], ['account_id' => $account('4110'), 'credit' => '500.00']],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $payroll = $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'payroll_summary',
            'accounting_date' => '2026-03-31',
            'description' => 'Gaji Maret',
            'values' => ['payroll_date' => '2026-03-31', 'gross_salary' => '1000.00', 'expense_account' => $account('1112'), 'payable_account' => $account('4110')],
        ])->assertCreated()->assertJsonCount(2, 'data.lines')->json('data.id');

        foreach ([$free, $payroll] as $id) {
            $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$id}/submit")->assertOk();
            $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$id}/approve")->assertOk();
            $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        }
    });
});
