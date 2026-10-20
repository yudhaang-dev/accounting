<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

// Currency and rate of entries (TE-FR-06).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->bank = curAccountId('1112');
    $this->sales = curAccountId('4110');
    $this->gain = curAccountId('4130');
    $this->loss = curAccountId('6220');

    mapExchangeDifferenceRoles($this->admin, $this->companyId);

    $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'spot', 'label' => 'Spot'])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'tax', 'label' => 'Kurs pajak'])->assertCreated();
    curRate('spot', '16000.00');

    $this->typeId = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'multi', 'label' => 'Multi'])->assertCreated()->json('data.id');
});

function curAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function curRate(string $type, string $rate, string $date = '2026-03-01'): void
{
    test()->actingAs(test()->admin)->postJson(test()->url.'/exchange-rates', ['rate_type' => $type, 'currency' => 'USD', 'effective_date' => $date, 'rate' => $rate])->assertCreated();
}

/** Publishes a version of the type; `$body` brings its package, `$mode` free_lines or structured. */
function curVersion(array $body = []): int
{
    $body += ['mode' => 'free_lines', 'fields' => []];
    $versions = test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types/'.test()->typeId.'/versions', $body)->assertCreated()->json('data.versions');
    $number = (int) collect($versions)->max('version');

    test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types/'.test()->typeId."/versions/{$number}/publish")->assertOk();

    return $number;
}

function curLines(string $currency = 'USD', array $extra = []): array
{
    return [
        ['account_id' => test()->bank, 'debit' => '100.00', 'currency' => $currency] + $extra,
        ['account_id' => test()->sales, 'credit' => '100.00', 'currency' => $currency] + $extra,
    ];
}

function curEntry(array $overrides = [])
{
    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', array_filter(array_merge([
        'transaction_type_key' => 'multi', 'accounting_date' => '2026-03-15', 'description' => 'Valas', 'lines' => curLines(),
    ], $overrides), fn ($value) => $value !== null));
}

function curAct(User $user, string $id, string $action)
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}");
}

function curJournal(string $entryId): array
{
    $journalId = DB::table('te_entries')->where('id', $entryId)->value('journal_id');

    return DB::table('gl_journal_lines')->where('journal_id', $journalId)->orderBy('line_number')->get()
        ->map(fn ($line) => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit, 'currency' => $line->currency, 'rate' => (float) $line->exchange_rate])->all();
}

describe('package of a version', function () {
    it('keeps the currencies, the rate type and the rate input policy with the version', function () {
        $number = curVersion(['allowed_currencies' => ['USD'], 'rate_type_key' => 'tax', 'allow_rate_input' => true]);

        $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types/{$this->typeId}")
            ->assertJsonPath('data.versions.1.version', $number)
            ->assertJsonPath('data.versions.1.allowed_currencies', ['USD'])
            ->assertJsonPath('data.versions.1.rate_type_key', 'tax')
            ->assertJsonPath('data.versions.1.allow_rate_input', true)
            ->assertJsonPath('data.versions.0.allowed_currencies', null)
            ->assertJsonPath('data.versions.0.allow_rate_input', false);
    });

    it('refuses an allowed currency the company has not activated', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", ['mode' => 'free_lines', 'fields' => [], 'allowed_currencies' => ['JPY']])
            ->assertUnprocessable()->assertJsonValidationErrors(['allowed_currencies.0']);
    });
});

describe('free-lines entries', function () {
    it('looks the rate up when it is submitted and posts the lines converted with it', function () {
        curVersion();

        $id = curEntry()->assertCreated()->assertJsonPath('data.lines.0.currency', 'USD')->assertJsonPath('data.lines.0.applied_rate', null)->json('data.id');

        curAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.lines.0.applied_rate', '16000.00');
        curAct($this->approver, $id, 'approve')->assertOk();
        curAct($this->approver, $id, 'post')->assertOk();

        expect(curJournal($id))->toBe([
            ['debit' => 1600000.0, 'credit' => 0.0, 'currency' => 'USD', 'rate' => 16000.0],
            ['debit' => 0.0, 'credit' => 1600000.0, 'currency' => 'USD', 'rate' => 16000.0],
        ]);
    });

    it('does not submit without a rate, and does not guess one (TE-AC-03)', function () {
        curVersion();

        // The only rate is from March 1st; there is none for a date before it.
        $id = curEntry(['accounting_date' => '2026-02-20'])->assertCreated()->json('data.id');

        curAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.status', 'draft');
    });

    it('uses the rate type of the version', function () {
        curRate('tax', '15500.00');
        curVersion(['rate_type_key' => 'tax']);

        $id = curEntry()->assertCreated()->json('data.id');

        curAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.lines.0.applied_rate', '15500.00');
    });

    it('takes the rate an entry brings only when the version lets it', function () {
        curVersion();

        curEntry(['lines' => curLines('USD', ['exchange_rate' => '15000'])])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.exchange_rate']);

        curVersion(['allow_rate_input' => true]);

        $id = curEntry(['lines' => curLines('USD', ['exchange_rate' => '15000'])])->assertCreated()->json('data.id');
        curAct($this->preparer, $id, 'submit')->assertOk()->assertJsonPath('data.lines.0.applied_rate', '15000')->assertJsonPath('data.lines.0.exchange_rate', '15000');
        curAct($this->approver, $id, 'approve')->assertOk();
        curAct($this->approver, $id, 'post')->assertOk();

        expect(curJournal($id)[0])->toMatchArray(['debit' => 1500000.0, 'rate' => 15000.0]);

        curEntry(['lines' => curLines('USD', ['exchange_rate' => '0'])])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.exchange_rate']);
    });

    it('refuses a currency the version does not accept or the company has not activated', function () {
        mapExchangeDifferenceRoles($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'EUR'])->assertCreated();
        curVersion(['allowed_currencies' => ['USD']]);

        curEntry(['lines' => curLines('EUR')])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.currency']);
        curEntry(['lines' => curLines('JPY')])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.currency']);
        curEntry()->assertCreated();
    });

    it('keeps the functional currency as no currency, and gives it no rate', function () {
        curVersion();

        curEntry(['lines' => curLines('IDR')])->assertCreated()->assertJsonPath('data.lines.0.currency', null);
        curEntry(['lines' => curLines('IDR', ['exchange_rate' => '1'])])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.exchange_rate']);
        curEntry(['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
    });
});

describe('structured entries', function () {
    function curStructured(array $extra = []): int
    {
        return curVersion($extra + [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'received', 'type' => 'amount', 'label' => 'Diterima'],
                ['name' => 'paid', 'type' => 'amount', 'label' => 'Dibayar'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'received', 'account' => ['source' => 'fixed', 'account_id' => test()->bank]],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'paid', 'account' => ['source' => 'fixed', 'account_id' => test()->sales]],
                ['type' => 'fx_difference'],
            ],
        ]);
    }

    function curStructuredEntry(array $overrides = [])
    {
        return curEntry(array_merge(['lines' => null, 'values' => ['received' => '100.00', 'paid' => '100.00']], $overrides));
    }

    it('puts the one currency of the entry on every line', function () {
        curStructured();

        curStructuredEntry(['currency' => 'USD'])->assertCreated()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.lines.0.currency', 'USD')->assertJsonPath('data.lines.1.currency', 'USD')
            ->assertJsonCount(2, 'data.lines');
    });

    it('carries what is left over in the functional currency to the exchange difference accounts (TE-AC-10)', function () {
        foreach (['fx_realized_gain' => $this->gain, 'fx_realized_loss' => $this->loss] as $role => $accountId) {
            mapRoleTo("{$role}", $accountId);
        }

        curStructured();

        $r = curStructuredEntry(['currency' => 'USD', 'values' => ['received' => '100.00', 'paid' => '99.00']]);
        $id = $r->assertCreated()->json('data.id');

        $submitted = curAct($this->preparer, $id, 'submit')->assertOk()->assertJsonCount(3, 'data.lines');

        expect($submitted->json('data.lines.2'))->toMatchArray(['account_id' => $this->gain, 'credit' => '16000.00', 'currency' => null]);

        curAct($this->approver, $id, 'approve')->assertOk();
        curAct($this->approver, $id, 'post')->assertOk();

        $journal = curJournal($id);

        expect(array_sum(array_column($journal, 'debit')))->toBe(array_sum(array_column($journal, 'credit')))->toBe(1600000.0);
    });

    it('rejects a rate the entry brings unless the version lets it, and a missing one on submit', function () {
        curStructured();

        curStructuredEntry(['currency' => 'USD', 'exchange_rate' => '15000'])->assertUnprocessable()->assertJsonValidationErrors(['exchange_rate']);

        $id = curStructuredEntry(['currency' => 'USD', 'accounting_date' => '2026-02-20'])->assertCreated()->json('data.id');
        curAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
    });
});
