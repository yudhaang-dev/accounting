<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\JournalLine;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Str;

// The group company of an intragroup line travels from the entry to the journal (GL-FR-15, CO-FR-07).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->receivables = docAccountId('1121');
    $this->bank = docAccountId('1112');
    $this->sales = docAccountId('4110');

    // The template does not restrict the sources of the control account, so the company does it.
    $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$this->receivables}")->json('data.lock_version');
    $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->receivables}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();

    $this->profileId = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$this->profileId}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $this->receivables])->assertOk();

    $this->sister = (string) Str::ulid();
});

/** @return array<int, JournalLine> by line number, starting at 1 */
function cpLines(string $journalId): array
{
    return JournalLine::withoutGlobalScope(CompanyScope::class)->where('journal_id', $journalId)->orderBy('line_number')->get()->keyBy('line_number')->all();
}

describe('a free-lines entry', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
    });

    function cpFreeEntry(array $first): string
    {
        return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', [
            'transaction_type_key' => 'general_journal',
            'accounting_date' => '2026-03-15',
            'description' => 'Penjualan ke perusahaan grup',
            'lines' => [
                ['account_id' => test()->bank, 'debit' => '1000.00'] + $first,
                ['account_id' => test()->sales, 'credit' => '1000.00'],
            ],
        ])->assertCreated()->json('data.id');
    }

    it('carries the counterparty of a line to the posted journal and shows it on the entry', function () {
        $id = cpFreeEntry(['counterparty_company_id' => $this->sister]);

        $this->actingAs($this->preparer)->getJson("{$this->url}/entries/{$id}")->assertOk()
            ->assertJsonPath('data.lines.0.counterparty_company_id', $this->sister)
            ->assertJsonPath('data.lines.1.counterparty_company_id', null);

        $posted = docPosted($id);
        $lines = cpLines($posted['journal_id']);

        expect($lines[1]->counterparty_company_id)->toBe($this->sister)->and($lines[2]->counterparty_company_id)->toBeNull();
    });

    it('refuses the company itself as the counterparty, and a value that is not an ID', function () {
        $id = cpFreeEntry(['counterparty_company_id' => $this->companyId]);

        docAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['lines.0.counterparty_company_id']);

        $this->actingAs($this->preparer)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'general_journal', 'accounting_date' => '2026-03-15', 'description' => 'x',
            'lines' => [['account_id' => $this->bank, 'debit' => '1', 'counterparty_company_id' => 'nope'], ['account_id' => $this->sales, 'credit' => '1']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.counterparty_company_id']);
    });
});

describe('a structured entry with a party (SL-FR-02)', function () {
    beforeEach(function () {
        $this->intragroup = docParty(['name' => 'PT Saudara', 'counterparty_company_id' => $this->sister]);
        $this->outside = docParty(['name' => 'PT Luar']);
    });

    it('tags the control account line with the group company of the party, and no other line', function () {
        docType('credit_sale', docInvoiceBody());

        $posted = docPosted(docEntry('credit_sale', ['party' => $this->intragroup, 'total' => '1000000'])->assertCreated()->json('data.id'));
        $lines = cpLines($posted['journal_id']);

        expect($lines[1]->counterparty_company_id)->toBe($this->sister)->and($lines[2]->counterparty_company_id)->toBeNull();
    });

    it('leaves the lines of a party without a group company untagged', function () {
        docType('credit_sale', docInvoiceBody());

        $posted = docPosted(docEntry('credit_sale', ['party' => $this->outside, 'total' => '1000000'])->assertCreated()->json('data.id'));

        expect(array_map(fn (JournalLine $line) => $line->counterparty_company_id, cpLines($posted['journal_id'])))->each->toBeNull();
    });

    it('tags the line of a rule that names the party field, so the revenue can be eliminated too', function () {
        docType('credit_sale', docInvoiceBody(['posting_rules' => [1 => ['counterparty_party_field' => 'party']]]));

        $posted = docPosted(docEntry('credit_sale', ['party' => $this->intragroup, 'total' => '1000000'])->assertCreated()->json('data.id'));
        $lines = cpLines($posted['journal_id']);

        expect($lines[1]->counterparty_company_id)->toBe($this->sister)->and($lines[2]->counterparty_company_id)->toBe($this->sister);
    });

    it('carries the same through a financial event', function () {
        docType('credit_sale', docInvoiceBody(['posting_rules' => [1 => ['counterparty_party_field' => 'party']]]));
        $client = registerClient(['scopes' => ['financial-events:write'], 'transaction_type_keys' => ['credit_sale']]);

        $event = evSend('POST', "{$this->url}/financial-events", [
            'transaction_type' => ['key' => 'credit_sale'],
            'accounting_date' => '2026-03-15',
            'currency' => 'IDR',
            'amounts' => ['total' => '1000000'],
            'party' => $this->intragroup,
            'references' => ['source_transaction_id' => 'INV-1'],
        ], evToken($client))->assertCreated()->json('data');

        docAct($this->approver, $event['event_id'], 'approve')->assertOk();
        $posted = docAct($this->approver, $event['event_id'], 'post')->assertOk()->json('data');

        expect(cpLines($posted['journal_id'])[2]->counterparty_company_id)->toBe($this->sister);
    });

    it('refuses a counterparty field that is not a party field of the version', function () {
        $response = docType('credit_sale', docInvoiceBody(['posting_rules' => [1 => ['counterparty_party_field' => 'total']]]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['posting_rules.1.counterparty_party_field']);
    });
});
