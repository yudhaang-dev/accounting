<?php

use App\Models\User;

// The roles a party field accepts (TE-FR-04, TE-FR-08, SL-FR-01, ADR-0015). The helpers are those of DocumentTest.

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->approver = memberWithRoles($this->companyId, 'approver');

    $this->receivables = docAccountId('1121');
    $this->sales = docAccountId('4110');

    $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$this->receivables}")->json('data.lock_version');
    $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->receivables}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();

    $profile = $this->actingAs($this->admin)->postJson("{$this->url}/posting-profiles", ['name' => 'Third parties'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->putJson("{$this->url}/posting-profiles/{$profile}/accounts/receivables_control", ['lock_version' => 0, 'account_id' => $this->receivables])->assertOk();

    $this->customer = docParty(['name' => 'PT Maju Jaya', 'roles' => ['customer']]);
    $this->employee = docParty(['name' => 'Siti Rahma', 'roles' => ['employee']]);
    $this->both = docParty(['name' => 'Budi Ganda', 'roles' => ['employee', 'shareholder']]);
});

function restrictedInvoice(array $roles): array
{
    $body = docInvoiceBody();
    $body['fields'][0]['allowed_roles'] = $roles;

    return $body;
}

describe('publishing a party field with allowed roles', function () {
    it('keeps the roles on the field and in the schema of the version', function () {
        $type = docType('only_customers', restrictedInvoice(['customer', 'customer']))->assertCreated()->json('data');
        $number = collect($type['versions'])->max('version');
        $schema = $this->actingAs($this->preparer)->getJson("{$this->url}/transaction-types/{$type['id']}/versions/{$number}/schema")->assertOk()->json('data');

        expect(collect($type['versions'])->last()['fields'][0]['allowed_roles'])->toBe(['customer'])
            ->and($schema['properties']['party']['x-allowed-roles'])->toBe(['customer']);
    });

    it('refuses a role that does not exist, is inactive, or sits on a field that is no party field', function () {
        docType('unknown_role', restrictedInvoice(['astronaut']))->assertStatus(422)->assertJsonValidationErrors(['fields.0.allowed_roles']);

        $role = collect($this->actingAs($this->admin)->getJson("{$this->url}/party-roles")->json('data'))->firstWhere('key', 'shareholder');
        $this->actingAs($this->admin)->patchJson("{$this->url}/party-roles/{$role['id']}", ['lock_version' => $role['lock_version'], 'status' => 'inactive'])->assertOk();
        docType('inactive_role', restrictedInvoice(['shareholder']))->assertStatus(422)->assertJsonValidationErrors(['fields.0.allowed_roles']);

        $body = docInvoiceBody();
        $body['fields'][3]['allowed_roles'] = ['customer'];
        docType('wrong_field', $body)->assertStatus(422)->assertJsonValidationErrors(['fields.3.allowed_roles']);
    });
});

describe('an entry whose party field has allowed roles (TE-FR-08)', function () {
    beforeEach(function () {
        docType('loan_sale', restrictedInvoice(['employee']))->assertCreated();
        docType('open_sale', docInvoiceBody())->assertCreated();
    });

    it('refuses a party without one of the roles on the field at submit', function () {
        $id = docEntry('loan_sale', ['party' => $this->customer, 'total' => '1000'])->assertCreated()->json('data.id');

        docAct($this->preparer, $id, 'submit')->assertUnprocessable()->assertJsonValidationErrors(['values.party']);
    });

    it('accepts a party that holds any of the roles, among others', function () {
        docPosted(docEntry('loan_sale', ['party' => $this->both, 'total' => '1000'])->assertCreated()->json('data.id'));
        docPosted(docEntry('loan_sale', ['party' => $this->employee, 'total' => '1000'])->assertCreated()->json('data.id'));
    });

    it('takes every party when the field names no roles', function () {
        docEntry('open_sale', ['party' => $this->customer, 'total' => '1000'])->assertCreated();
        docEntry('open_sale', ['party' => $this->employee, 'total' => '1000'])->assertCreated();
    });
});

describe('the list of parties for such a field (TE-FR-04)', function () {
    it('filters by any of the roles', function () {
        $names = fn (array $roles) => collect($this->actingAs($this->admin)->getJson("{$this->url}/parties?".http_build_query(['filter' => ['roles' => $roles]]))->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($names(['employee']))->toBe(['Budi Ganda', 'Siti Rahma'])
            ->and($names(['customer', 'shareholder']))->toBe(['Budi Ganda', 'PT Maju Jaya']);
    });
});
