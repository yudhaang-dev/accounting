<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;

// The description of a transaction type: when it is used, written by the accountant for the catalog an
// operator picks from (TE-FR-01).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
});

describe('creating a type with a description', function () {
    it('keeps it and returns it with the type', function () {
        $id = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", [
            'key' => 'petty_expense', 'label' => 'Biaya Kas Kecil', 'description' => 'Uang kecil yang dikeluarkan dari laci kas.',
        ])->assertCreated()->assertJsonPath('data.description', 'Uang kecil yang dikeluarkan dari laci kas.')->json('data.id');

        $this->actingAs($this->preparer)->getJson("{$this->url}/transaction-types/{$id}")->assertOk()
            ->assertJsonPath('data.description', 'Uang kecil yang dikeluarkan dari laci kas.');
        $this->actingAs($this->preparer)->getJson("{$this->url}/transaction-types")->assertOk()
            ->assertJsonPath('data.0.description', 'Uang kecil yang dikeluarkan dari laci kas.');
    });

    it('is empty when none was given', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'plain', 'label' => 'Biasa'])
            ->assertCreated()->assertJsonPath('data.description', null);
    });

    it('refuses a description longer than the column keeps', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'long', 'label' => 'Panjang', 'description' => str_repeat('a', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors(['description']);
    });
});

describe('changing the description of a type', function () {
    beforeEach(function () {
        $this->typeId = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'petty_expense', 'label' => 'Biaya Kas Kecil'])->assertCreated()->json('data.id');
    });

    it('sets it, trims it, and leaves the key and the label alone', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/description", ['description' => "  Uang kecil dari laci kas.  \n"])
            ->assertOk()->assertJsonPath('data.description', 'Uang kecil dari laci kas.')->assertJsonPath('data.key', 'petty_expense')->assertJsonPath('data.label', 'Biaya Kas Kecil');

        expect(DB::table('te_transaction_types')->where('id', $this->typeId)->value('description'))->toBe('Uang kecil dari laci kas.');
    });

    it('clears it when it is empty or null, which must still be sent', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/description", ['description' => 'Sementara'])->assertOk();

        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/description", ['description' => '   '])
            ->assertOk()->assertJsonPath('data.description', null);
        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/description", [])->assertUnprocessable()->assertJsonValidationErrors(['description']);
    });

    it('records who changed it and what it was', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/description", ['description' => 'Pertama'])->assertOk();
        $this->actingAs($this->admin)->putJson("{$this->url}/transaction-types/{$this->typeId}/description", ['description' => 'Kedua'])->assertOk();

        $entry = AuditEntry::where('event', 'transaction_type.description_changed')->where('company_id', $this->companyId)->orderByDesc('id')->first();

        expect(AuditEntry::where('event', 'transaction_type.description_changed')->where('company_id', $this->companyId)->count())->toBe(2)
            ->and($entry->attribute_changes['old']['description'])->toBe('Pertama')
            ->and($entry->attribute_changes['attributes']['description'])->toBe('Kedua');
    });

    it('needs the permission to manage policies', function () {
        $this->actingAs($this->preparer)->putJson("{$this->url}/transaction-types/{$this->typeId}/description", ['description' => 'Tidak boleh'])->assertForbidden();
    });

    it('does not reach the type of another company', function () {
        $outsider = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($outsider, 'Other');

        $this->actingAs($outsider)->putJson("/api/v1/companies/{$otherCompany}/transaction-types/{$this->typeId}/description", ['description' => 'Bukan milikmu'])->assertNotFound();
    });
});

describe('the templates', function () {
    it('describe every type, and an import keeps the description', function () {
        foreach (['general', 'daily-operations'] as $template) {
            $types = $this->actingAs($this->admin)->getJson("{$this->url}/transaction-type-templates/{$template}")->assertOk()->json('data.types');

            foreach ($types as $type) {
                expect($type['description'] ?? '')->not->toBe('', "{$template}/{$type['key']} has no description");
            }
        }

        confirmChartOfAccounts($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/import-template", ['template' => 'general', 'confirmed' => true, 'keys' => ['general_journal']])->assertCreated();

        $this->actingAs($this->preparer)->getJson("{$this->url}/transaction-types")->assertOk()
            ->assertJsonPath('data.0.key', 'general_journal')
            ->assertJsonPath('data.0.description', 'Jurnal bebas untuk akuntan: memilih akun dan mengisi debit dan kredit sendiri.');
    });
});
