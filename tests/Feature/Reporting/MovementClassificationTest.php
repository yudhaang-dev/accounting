<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->cash = mcAccountId('1111');
    $this->bank = mcAccountId('1112');
    $this->sales = mcAccountId('4110');
    $this->expense = mcAccountId('6220');

    mcPost('2026-02-05', $this->bank, $this->sales, '1000.00', 'sales.invoice');
    mcPost('2026-02-10', $this->bank, $this->sales, '300.00', 'sales.cash');
    mcPost('2026-02-20', $this->expense, $this->bank, '200.00', 'expense.payment');
    mcPost('2026-02-25', $this->expense, $this->bank, '50.00', null);

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function mcAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function mcPost(string $date, string $debit, string $credit, string $amount, ?string $key): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
        transactionTypeKey: $key,
    ));
}

/** @return array<string, mixed> */
function mcStructure(): array
{
    return [
        'rows' => [['key' => 'operating', 'label' => 'Operating'], ['key' => 'invoices', 'label' => 'Invoices']],
        'rules' => [
            ['row' => 'invoices', 'accounts' => [['type' => 'account', 'id' => test()->sales]], 'transaction_type_keys' => ['sales.invoice']],
            ['row' => 'operating', 'accounts' => [['type' => 'class', 'class' => 'income'], ['type' => 'class', 'class' => 'expense']], 'transaction_type_keys' => []],
        ],
    ];
}

function mcCreate(array $overrides = [])
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/movement-classifications', [
        'key' => 'cash_flow', 'name' => 'Cash flow', 'structure' => mcStructure(), ...$overrides,
    ]);
}

function mcRun(string $id, string $query = '', ?User $as = null)
{
    return test()->actingAs($as ?? test()->viewer)->getJson(test()->url."/movement-classifications/{$id}/result?from=2026-02-01&to=2026-02-28{$query}");
}

function mcRow(array $result, string $key): array
{
    return collect($result['rows'])->firstWhere('key', $key);
}

describe('cash_equivalents role (RP-FR-03)', function () {
    it('maps one or more asset accounts as versions and audits the change', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/reporting/account-role-mappings")->assertOk()
            ->assertJsonPath('data.0.key', 'cash_equivalents')->assertJsonPath('data.0.account_ids', [])->assertJsonPath('data.0.lock_version', 0);

        $first = $this->actingAs($this->admin)->putJson("{$this->url}/reporting/account-role-mappings/cash_equivalents", ['lock_version' => 0, 'account_ids' => [$this->cash, $this->bank]])->assertOk();
        $second = $this->actingAs($this->admin)->putJson("{$this->url}/reporting/account-role-mappings/cash_equivalents", ['lock_version' => 1, 'account_ids' => [$this->bank]])->assertOk();

        expect($first->json('data.lock_version'))->toBe(1)
            ->and($first->json('data.effective_date'))->toBe('1970-01-01')
            ->and($second->json('data.account_ids'))->toBe([$this->bank])
            ->and(DB::table('rp_account_role_mappings')->where('company_id', $this->companyId)->count())->toBe(2)
            ->and(AuditEntry::query()->where('event', 'account_role.changed')->count())->toBe(2);

        $this->actingAs($this->viewer)->getJson("{$this->url}/reporting/account-role-mappings")->assertJsonPath('data.0.account_ids', [$this->bank])->assertJsonPath('data.0.lock_version', 2);
    });

    it('refuses accounts that are not asset leaves, a stale version and a user without the permission', function () {
        $put = fn (array $body, ?User $as = null) => $this->actingAs($as ?? $this->admin)->putJson("{$this->url}/reporting/account-role-mappings/cash_equivalents", $body);

        $put(['lock_version' => 0, 'account_ids' => [$this->bank, $this->sales]])->assertUnprocessable()->assertJsonValidationErrors('account_ids.1');
        $put(['lock_version' => 0, 'account_ids' => []])->assertUnprocessable();
        $put(['lock_version' => 0, 'account_ids' => [$this->bank]])->assertOk();
        $put(['lock_version' => 0, 'account_ids' => [$this->cash]])->assertStatus(409);
        $put(['lock_version' => 1, 'account_ids' => [$this->cash]], $this->viewer)->assertForbidden();
    });

    it('makes a mapped account that is no longer valid visible', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/reporting/account-role-mappings/cash_equivalents", ['lock_version' => 0, 'account_ids' => [$this->bank]])->assertOk();
        Account::withoutGlobalScope(CompanyScope::class)->whereKey($this->bank)->update(['status' => 'inactive']);

        $problems = $this->actingAs($this->viewer)->getJson("{$this->url}/reporting/account-role-mappings")->assertOk()->json('data.0.problems');

        expect($problems)->toHaveKey($this->bank);
    });
});

describe('movement classification definitions (RP-FR-03)', function () {
    it('creates version 1, shows it and refuses a repeated key', function () {
        $response = mcCreate()->assertCreated();

        expect($response->json('data.version'))->toBe(1)
            ->and($response->json('data.structure.rules'))->toHaveCount(2);

        $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/{$response->json('data.id')}")->assertOk()->assertJsonPath('data.key', 'cash_flow');
        mcCreate()->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->actingAs($this->viewer)->postJson("{$this->url}/movement-classifications", ['key' => 'other', 'name' => 'Other', 'structure' => mcStructure()])->assertForbidden();
    });

    it('adds a version when the rules change and keeps the earlier one readable', function () {
        $id = mcCreate()->json('data.id');
        $changed = mcStructure();
        $changed['rules'][1]['row'] = 'invoices';

        $this->actingAs($this->admin)->putJson("{$this->url}/movement-classifications/{$id}", ['lock_version' => 1, 'structure' => $changed])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.lock_version', 2);

        $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/{$id}?version=1")->assertOk()->assertJsonPath('data.structure.rules.1.row', 'operating');
        $this->actingAs($this->admin)->putJson("{$this->url}/movement-classifications/{$id}", ['lock_version' => 1, 'name' => 'Stale'])->assertStatus(409);
    });

    it('refuses rules that would send movement nowhere', function () {
        $structure = mcStructure();
        $structure['rules'][0]['row'] = 'missing';
        $structure['rules'][1]['accounts'] = [];
        $structure['rows'][] = ['key' => 'unclassified', 'label' => 'Reserved'];

        mcCreate(['structure' => $structure])->assertUnprocessable()->assertJsonValidationErrors(['structure.rules.0.row', 'structure.rules.1.accounts', 'structure.rows.2.key']);
    });
});

describe('classifying movement (RP-FR-03)', function () {
    it('matches rules by account and transaction type key, the first matching rule winning', function () {
        $id = mcCreate()->json('data.id');

        $result = mcRun($id)->assertOk()->json('data');

        // Sales 1000 by key sales.invoice -> invoices; sales 300 (key sales.cash) and the expenses fall to the second rule.
        expect(mcRow($result, 'invoices'))->toMatchArray(['debit' => '0.00', 'credit' => '1000.00', 'net' => '-1000.00'])
            ->and(mcRow($result, 'operating'))->toMatchArray(['debit' => '250.00', 'credit' => '300.00', 'net' => '-50.00']);
    });

    it('puts movement without a rule in the unclassified row and keeps the total equal to the ledger', function () {
        $id = mcCreate()->json('data.id');

        $result = mcRun($id)->json('data');

        // The bank lines match no rule: 1550 debit and 250 credit.
        expect(mcRow($result, 'unclassified'))->toMatchArray(['debit' => '1300.00', 'credit' => '250.00', 'net' => '1050.00'])
            ->and($result['unclassified'])->toHaveCount(4)
            ->and($result['unclassified'][0]['code'])->toBe('1112')
            ->and($result['total'])->toBe(['debit' => '1550.00', 'credit' => '1550.00', 'net' => '0.00']);
    });

    it('leaves the unclassified row out when every line is classified', function () {
        $structure = mcStructure();
        $structure['rules'][] = ['row' => 'operating', 'accounts' => [['type' => 'class', 'class' => 'asset']], 'transaction_type_keys' => []];
        $id = mcCreate(['structure' => $structure])->json('data.id');

        $result = mcRun($id)->json('data');

        expect(collect($result['rows'])->pluck('key')->all())->toBe(['operating', 'invoices'])
            ->and($result['unclassified'])->toBe([]);
    });

    it('applies the report filters and the pinned version', function () {
        $id = mcCreate()->json('data.id');
        $changed = mcStructure();
        $changed['rules'] = [['row' => 'invoices', 'accounts' => [['type' => 'class', 'class' => 'income']], 'transaction_type_keys' => []]];
        $this->actingAs($this->admin)->putJson("{$this->url}/movement-classifications/{$id}", ['lock_version' => 1, 'structure' => $changed])->assertOk();

        $filtered = mcRun($id, '&transaction_type_keys[]=sales.cash')->assertOk()->json('data');
        $pinned = mcRun($id, '&version=1&transaction_type_keys[]=sales.cash')->assertOk()->json('data');

        expect(mcRow($filtered, 'invoices')['credit'])->toBe('300.00')
            ->and($filtered['classification']['version'])->toBe(2)
            ->and($filtered['parameters']['transaction_type_keys'])->toBe(['sales.cash'])
            ->and(mcRow($pinned, 'operating')['credit'])->toBe('300.00')
            ->and($pinned['classification']['version'])->toBe(1);
    });

    it('needs a date range', function () {
        $id = mcCreate()->json('data.id');

        $this->actingAs($this->viewer)->getJson("{$this->url}/movement-classifications/{$id}/result")->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    });
});
