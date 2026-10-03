<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');

    foreach (['general_journal' => 'Jurnal Umum', 'adjustment' => 'Penyesuaian'] as $key => $label) {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => $key, 'label' => $label])->assertCreated();
    }
});

function numberingEntry(string $typeKey): string
{
    $account = fn (string $code) => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');

    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', [
        'transaction_type_key' => $typeKey,
        'accounting_date' => '2026-03-15',
        'description' => 'Penjualan tunai',
        'lines' => [
            ['account_id' => $account('1112'), 'debit' => '1000.00'],
            ['account_id' => $account('4110'), 'credit' => '1000.00'],
        ],
    ])->assertCreated()->json('data.id');
}

function numberingTypeId(string $key): string
{
    return collect(test()->actingAs(test()->admin)->getJson(test()->url.'/transaction-types')->json('data'))->firstWhere('key', $key)['id'];
}

function setNumbering(string $key, array $body)
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/transaction-types/'.numberingTypeId($key).'/numbering', $body + [
        'pattern' => 'X/{yyyy}/{seq}', 'padding' => 4, 'reset' => 'yearly', 'policy' => 'on_create',
    ]);
}

describe('entry numbers per transaction type (TE-FR-16)', function () {
    it('gives each type its own prefix, so numbers never collide across types', function () {
        $first = numberingEntry('general_journal');
        $second = numberingEntry('adjustment');

        $numbers = collect([$first, $second])->map(fn (string $id) => $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->json('data.number'));

        expect($numbers->all())->toBe(['GENERAL-JOURNAL/2026/03/000001', 'ADJUSTMENT/2026/03/000001']);
    });

    it('lets a company define the series of a type and keeps counting from the issued numbers', function () {
        numberingEntry('general_journal');

        setNumbering('general_journal', ['pattern' => 'JU-{yyyy}-{seq}', 'padding' => 4, 'reset' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('data.numbering', ['pattern' => 'JU-{yyyy}-{seq}', 'padding' => 4, 'reset' => 'yearly', 'policy' => 'on_create']);

        $id = numberingEntry('general_journal');

        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.number', 'JU-2026-0001');
    });

    it('reports the default series of a type that has not issued a number yet', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types")
            ->assertJsonPath('data.1.key', 'general_journal')
            ->assertJsonPath('data.1.numbering', ['pattern' => 'GENERAL-JOURNAL/{yyyy}/{mm}/{seq}', 'padding' => 6, 'reset' => 'monthly', 'policy' => 'on_create']);
    });

    it('refuses a prefix another type uses, even one it only gets by default', function () {
        setNumbering('general_journal', ['pattern' => 'ADJUSTMENT/{yyyy}/{seq}'])->assertUnprocessable()->assertJsonValidationErrors('pattern');
        setNumbering('general_journal', ['pattern' => 'ADJUSTMENT-{yyyy}/{seq}'])->assertUnprocessable();

        setNumbering('adjustment', ['pattern' => 'ADJ-{yyyy}-{seq}'])->assertOk();
        setNumbering('general_journal', ['pattern' => 'ADJ/{yyyy}-{seq}'])->assertUnprocessable();
    });

    it('refuses a pattern without a fixed prefix or without the tokens its reset needs', function () {
        setNumbering('general_journal', ['pattern' => '{yyyy}-{seq}'])->assertUnprocessable()->assertJsonValidationErrors('pattern');
        setNumbering('general_journal', ['pattern' => 'JU-{seq}', 'reset' => 'monthly'])->assertUnprocessable()->assertJsonValidationErrors('pattern');
        setNumbering('general_journal', ['policy' => 'never'])->assertUnprocessable()->assertJsonValidationErrors('policy');
    });

    it('is managed with policies.manage only', function () {
        $id = numberingTypeId('general_journal');

        $this->actingAs($this->preparer)
            ->putJson("{$this->url}/transaction-types/{$id}/numbering", ['pattern' => 'X/{seq}', 'padding' => 4, 'reset' => 'never', 'policy' => 'on_create'])
            ->assertForbidden();
    });

    it('numbers an entry on submit when the type says so, and keeps the number afterwards', function () {
        setNumbering('general_journal', ['pattern' => 'JU-{yyyy}-{seq}', 'policy' => 'on_submit'])->assertOk();

        $id = numberingEntry('general_journal');
        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.number', null);

        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$id}/submit")->assertOk()->assertJsonPath('data.number', 'JU-2026-0001');
        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->assertJsonPath('data.number', 'JU-2026-0001');

        // A second entry cancelled while still a draft never takes a number: no gap.
        $cancelled = numberingEntry('general_journal');
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.number', null);

        $next = numberingEntry('general_journal');
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$next}/submit")->assertOk()->assertJsonPath('data.number', 'JU-2026-0002');
    });

    it('does not burn a number when a submit fails validation', function () {
        setNumbering('general_journal', ['pattern' => 'JU-{yyyy}-{seq}', 'policy' => 'on_submit'])->assertOk();

        $bad = $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal',
            'accounting_date' => '2026-03-15',
            'description' => 'Tidak seimbang',
            'lines' => [['account_id' => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '1112')->value('id'), 'debit' => '10.00']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$bad}/submit")->assertUnprocessable();

        $good = numberingEntry('general_journal');
        $this->actingAs($this->preparer)->postJson("{$this->url}/entries/{$good}/submit")->assertOk()->assertJsonPath('data.number', 'JU-2026-0001');
    });
});
