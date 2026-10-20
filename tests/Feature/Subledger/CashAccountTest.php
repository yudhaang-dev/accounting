<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Subledger\Domain\CashTransaction;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);

    $this->pettyCash = cashLedgerAccount($this->companyId, '1111');
    $this->bank = cashLedgerAccount($this->companyId, '1112');
    $this->foreignBank = cashLedgerAccount($this->companyId, '1115');
    $this->payables = cashLedgerAccount($this->companyId, '2110');
    $this->parent = cashLedgerAccount($this->companyId, '1110');

    // The template does not restrict the sources of control accounts, so the company does it.
    foreach ([$this->pettyCash, $this->bank, $this->foreignBank, $this->payables] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }
});

function cashLedgerAccount(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function newBank(array $overrides = [])
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/cash-accounts', array_merge([
        'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890',
        'currency' => 'IDR', 'account_id' => test()->bank,
    ], $overrides));
}

function recordCash(string $cashAccountId, array $overrides = []): CashTransaction
{
    return app(CompanyContext::class)->run(test()->companyId, fn () => CashTransaction::create(array_merge([
        'cash_account_id' => $cashAccountId,
        'direction' => 'in',
        'amount' => '1500000',
        'currency' => 'IDR',
        'transaction_date' => '2026-03-10',
        'reference' => 'INV-001',
    ], $overrides)));
}

describe('cash accounts (SL-FR-03)', function () {
    it('creates a bank account and a cash box, each with one currency and one ledger account', function () {
        $bank = newBank()->assertCreated()->json('data');
        $cash = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
            'name' => 'Kas Kecil', 'kind' => 'cash', 'currency' => 'IDR', 'account_id' => $this->pettyCash,
        ])->assertCreated()->json('data');

        expect($bank)->toMatchArray([
            'name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '123-456-7890',
            'currency' => 'IDR', 'account_id' => $this->bank, 'status' => 'active', 'lock_version' => 1,
        ])->and($cash['bank_name'])->toBeNull()
            ->and(AuditEntry::where('event', 'cash_account.created')->where('company_id', $this->companyId)->count())->toBe(2);
    });

    it('needs bank details for a bank account and none for a cash box', function () {
        newBank(['bank_name' => null])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
        newBank(['account_number' => null])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", [
            'name' => 'Kas', 'kind' => 'cash', 'bank_name' => 'BCA', 'currency' => 'IDR', 'account_id' => $this->pettyCash,
        ])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
    });

    it('accepts only an active asset leaf that takes the subledger source and nothing else, and that no other cash account uses', function () {
        newBank(['account_id' => $this->parent])->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        newBank(['account_id' => $this->payables])->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        newBank(['account_id' => str_repeat('0', 26)])->assertUnprocessable()->assertJsonValidationErrors(['account_id']);

        $open = $this->actingAs($this->admin)->postJson("{$this->url}/accounts", [
            'code' => '1116', 'name' => 'Bank Terbuka', 'class' => 'asset', 'parent_id' => $this->parent,
        ])->assertCreated()->json('data.id');
        newBank(['account_id' => $open])->assertUnprocessable()->assertJsonValidationErrors(['account_id']);

        newBank()->assertCreated();
        newBank(['name' => 'BCA Lain'])->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        newBank(['account_id' => $this->pettyCash])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    });

    it('needs a currency activated for the company that the ledger account accepts', function () {
        newBank(['currency' => 'USD', 'account_id' => $this->foreignBank])->assertUnprocessable()->assertJsonValidationErrors(['currency']);

        mapExchangeDifferenceRoles($this->admin, $this->companyId);

        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

        newBank(['currency' => 'USD', 'account_id' => $this->foreignBank])->assertCreated()->assertJsonPath('data.currency', 'USD');

        // The ledger account of the petty cash box takes the functional currency only.
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$this->pettyCash}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->pettyCash}", ['lock_version' => $version, 'currency_restriction' => 'functional_only'])->assertOk();

        newBank(['name' => 'Kas USD', 'kind' => 'cash', 'bank_name' => null, 'account_number' => null, 'currency' => 'USD', 'account_id' => $this->pettyCash])
            ->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
    });

    it('changes the name and bank details but never the currency or ledger account, and answers VERSION_CONFLICT', function () {
        $id = newBank()->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->patchJson("{$this->url}/cash-accounts/{$id}", [
            'lock_version' => 1, 'name' => 'BCA Utama', 'account_number' => '999', 'currency' => 'USD', 'account_id' => $this->pettyCash, 'kind' => 'cash',
        ])->assertOk()->assertJsonPath('data.name', 'BCA Utama')->assertJsonPath('data.account_number', '999')
            ->assertJsonPath('data.currency', 'IDR')->assertJsonPath('data.account_id', $this->bank)->assertJsonPath('data.kind', 'bank');

        $this->actingAs($this->admin)->patchJson("{$this->url}/cash-accounts/{$id}", ['lock_version' => 1, 'name' => 'Basi'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->actingAs($this->admin)->patchJson("{$this->url}/cash-accounts/{$id}", ['lock_version' => 2, 'bank_name' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind']);

        expect(AuditEntry::where('event', 'cash_account.updated')->count())->toBe(1);
    });

    it('is never deleted, only deactivated and reactivated, and stays listed and filterable', function () {
        $id = newBank()->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->deleteJson("{$this->url}/cash-accounts/{$id}")->assertStatus(405);
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        $this->actingAs($this->admin)->getJson("{$this->url}/cash-accounts?filter[status]=inactive&filter[kind]=bank")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/cash-accounts?filter[kind]=cash")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('keeps cash accounts apart per company', function () {
        $id = newBank()->assertCreated()->json('data.id');
        $other = User::factory()->create();
        [$otherCompanyId] = createWorkspaceAs($other, 'Beta');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompanyId}/cash-accounts/{$id}")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompanyId}/cash-accounts")->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('cash transactions and clearing (SL-FR-11)', function () {
    beforeEach(function () {
        $this->cashAccountId = newBank()->assertCreated()->json('data.id');
    });

    it('lists transactions newest first and filters by account, cleared status and date', function () {
        $other = $this->actingAs($this->admin)->postJson("{$this->url}/cash-accounts", ['name' => 'Kas Kecil', 'kind' => 'cash', 'currency' => 'IDR', 'account_id' => $this->pettyCash])->json('data.id');
        $march = recordCash($this->cashAccountId, ['transaction_date' => '2026-03-10', 'reference' => 'A']);
        $april = recordCash($this->cashAccountId, ['transaction_date' => '2026-04-05', 'direction' => 'out', 'amount' => '250000.5', 'reference' => 'B']);
        recordCash($other, ['reference' => 'C']);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$march->id}/clear", ['cleared_on' => '2026-03-12'])->assertOk();

        $references = fn (string $query) => collect($this->actingAs($this->admin)->getJson("{$this->url}/cash-transactions{$query}")->assertOk()->json('data'))->pluck('reference')->all();

        expect($references("?filter[cash_account_id]={$this->cashAccountId}"))->toBe(['B', 'A'])
            ->and($references("?filter[cash_account_id]={$this->cashAccountId}&filter[cleared]=1"))->toBe(['A'])
            ->and($references("?filter[cash_account_id]={$this->cashAccountId}&filter[cleared]=0"))->toBe(['B'])
            ->and($references('?filter[date_from]=2026-04-01'))->toBe(['B'])
            ->and($references('?filter[date_to]=2026-03-31&filter[cash_account_id]='.$this->cashAccountId))->toBe(['A']);

        $this->actingAs($this->admin)->getJson("{$this->url}/cash-transactions/{$april->id}")
            ->assertOk()->assertJsonPath('data.direction', 'out')->assertJsonPath('data.amount', '250000.500000')->assertJsonPath('data.cleared', false);
    });

    it('clears a transaction with its date and the user, and audits it', function () {
        $transaction = recordCash($this->cashAccountId);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/clear", ['cleared_on' => '2026-03-15'])
            ->assertOk()->assertJsonPath('data.cleared', true)->assertJsonPath('data.cleared_on', '2026-03-15')->assertJsonPath('data.cleared_by', $this->admin->id);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/clear")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $audit = AuditEntry::where('event', 'cash_transaction.cleared')->firstOrFail();
        expect($audit->attribute_changes['attributes']['cleared_on'])->toBe('2026-03-15')->and((int) $audit->causer_id)->toBe($this->admin->id);
    });

    it('defaults the clearing date to today and refuses a date before the transaction or in the future', function () {
        $transaction = recordCash($this->cashAccountId, ['transaction_date' => '2026-03-10']);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/clear", ['cleared_on' => '2026-03-09'])
            ->assertUnprocessable()->assertJsonValidationErrors(['cleared_on']);
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/clear", ['cleared_on' => now()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['cleared_on']);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/clear")
            ->assertOk()->assertJsonPath('data.cleared_on', now()->toDateString());
    });

    it('takes the cleared mark off again and audits it', function () {
        $transaction = recordCash($this->cashAccountId);

        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/unclear")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/clear", ['cleared_on' => '2026-03-15'])->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/cash-transactions/{$transaction->id}/unclear")
            ->assertOk()->assertJsonPath('data.cleared', false)->assertJsonPath('data.cleared_on', null)->assertJsonPath('data.cleared_by', null);

        $audit = AuditEntry::where('event', 'cash_transaction.uncleared')->firstOrFail();
        expect($audit->attribute_changes['old']['cleared_on'])->toBe('2026-03-15');
    });

    it('lets the database refuse any change other than the cleared status, and any delete', function () {
        $transaction = recordCash($this->cashAccountId);

        expect(fn () => DB::table('sl_cash_transactions')->where('id', $transaction->id)->update(['amount' => '1']))->toThrow(QueryException::class);
        expect(fn () => DB::table('sl_cash_transactions')->where('id', $transaction->id)->update(['reference' => 'X']))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('sl_cash_transactions')->where('id', $transaction->id)->delete()))->toThrow(Exception::class);
        expect(fn () => recordCash($this->cashAccountId, ['amount' => '0']))->toThrow(QueryException::class);
    });

    it('lets a viewer read but not clear, and a clerk without the permission not manage accounts', function () {
        $transaction = recordCash($this->cashAccountId);
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/cash-accounts")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/cash-transactions")->assertOk();
        $this->actingAs($viewer)->postJson("{$this->url}/cash-transactions/{$transaction->id}/clear")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/cash-transactions/{$transaction->id}/unclear")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/cash-accounts/{$this->cashAccountId}/deactivate")->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$this->url}/cash-accounts/{$this->cashAccountId}", ['lock_version' => 1, 'name' => 'X'])->assertForbidden();
    });
});
