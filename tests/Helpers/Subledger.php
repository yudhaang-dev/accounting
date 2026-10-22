<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use Illuminate\Support\Facades\DB;

/** A receipt that names the open items it settles. */
function docSettlingBody(array $overrides = []): array
{
    return docPaymentBody(array_replace_recursive([
        'fields' => [4 => ['name' => 'allocs', 'type' => 'allocations', 'label' => 'Alokasi', 'required' => false]],
        'subledger_route' => ['allocations_field' => 'allocs'],
    ], $overrides));
}

/** Posts an invoice of the customer and returns the ID of its open item. */
function docOpenInvoice(string $total = '1000000', ?string $party = null): string
{
    $id = docEntry('credit_sale', ['party' => $party ?? test()->customer, 'total' => $total])->assertCreated()->json('data.id');
    docPosted($id);

    return (string) DB::table('sl_open_items')->where('kind', 'invoice')->orderByDesc('id')->value('id');
}

function docReceipt(string $total, array $allocations = [], ?string $party = null)
{
    return docEntry('receipt', array_filter(['party' => $party ?? test()->customer, 'bank' => test()->cashAccount, 'total' => $total, 'allocs' => $allocations ?: null]));
}

function docItem(string $id): object
{
    return DB::table('sl_open_items')->where('id', $id)->first();
}

/** Carries a reversal entry through approval and posting; returns the posted entry. */
function reversePosted(string $journalId, array $body = []): array
{
    $id = reverseRequest($journalId, $body)->assertCreated()->json('data.id');

    return docPosted($id);
}

function journalOfDocument(string $itemId): string
{
    return (string) DB::table('sl_documents')->where('id', DB::table('sl_open_items')->where('id', $itemId)->value('document_id'))->value('journal_id');
}

function docParty(array $overrides = []): string
{
    return (string) test()->actingAs(test()->admin)->postJson(test()->url.'/parties', array_merge(['name' => 'PT Maju Jaya', 'is_customer' => true], $overrides))->assertCreated()->json('data.id');
}

function docFixed(string $accountId): array
{
    return ['source' => 'fixed', 'account_id' => $accountId];
}

function docControl(string $partyField = 'party'): array
{
    return ['source' => 'control_account', 'party_field' => $partyField];
}

/** An invoice to a customer: the receivable against revenue. */
function docInvoiceBody(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'structured',
        'fields' => [
            ['name' => 'party', 'type' => 'party', 'label' => 'Pelanggan'],
            ['name' => 'number', 'type' => 'reference', 'label' => 'Nomor', 'required' => false],
            ['name' => 'due', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
            ['name' => 'total', 'type' => 'amount', 'label' => 'Total'],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => docFixed(test()->sales), 'apply_dimensions' => false, 'reuses_field' => true],
        ],
        'subledger_route' => ['kind' => 'invoice', 'control_role' => 'receivables_control', 'party_field' => 'party', 'document_number_field' => 'number', 'due_date_field' => 'due', 'cash_account_field' => null],
    ], $overrides);
}

/** A receipt from a customer: cash against the receivable. */
function docPaymentBody(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'structured',
        'fields' => [
            ['name' => 'party', 'type' => 'party', 'label' => 'Pelanggan'],
            ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Rekening'],
            ['name' => 'number', 'type' => 'reference', 'label' => 'Nomor', 'required' => false],
            ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => docControl(), 'apply_dimensions' => false, 'reuses_field' => true],
        ],
        'subledger_route' => ['kind' => 'payment', 'control_role' => 'receivables_control', 'party_field' => 'party', 'document_number_field' => 'number', 'due_date_field' => null, 'cash_account_field' => 'bank'],
    ], $overrides);
}

/** Creates a type of the key and publishes a version of the body; returns the response of the draft when it is refused. */
function docType(string $key, array $body)
{
    $typeId = test()->actingAs(test()->admin)->postJson(test()->url.'/transaction-types', ['key' => $key, 'label' => ucfirst($key)])->assertCreated()->json('data.id');
    $draft = test()->actingAs(test()->admin)->postJson(test()->url."/transaction-types/{$typeId}/versions", $body);

    if ($draft->status() !== 201) {
        return $draft;
    }

    $number = (int) collect($draft->json('data.versions'))->max('version');
    test()->actingAs(test()->admin)->postJson(test()->url."/transaction-types/{$typeId}/versions/{$number}/publish")->assertOk();

    return $draft;
}

function docEntry(string $key, array $values, array $overrides = [])
{
    return test()->actingAs(test()->preparer)->postJson(test()->url.'/entries', array_merge([
        'transaction_type_key' => $key,
        'accounting_date' => '2026-03-15',
        'description' => 'Dokumen subledger',
        'values' => $values,
    ], $overrides));
}

function docAct(User $user, string $id, string $action)
{
    return test()->actingAs($user)->postJson(test()->url."/entries/{$id}/{$action}");
}

/** Submits, approves and posts an entry; returns the posted entry. */
function docPosted(string $entryId): array
{
    docAct(test()->preparer, $entryId, 'submit')->assertOk();
    docAct(test()->approver, $entryId, 'approve')->assertOk();

    return docAct(test()->approver, $entryId, 'post')->assertOk()->assertJsonPath('data.status', 'posted')->json('data');
}

/** The gain account is revenue and the loss account an expense, as the roles need. */
function mapFxRoles(): void
{
    foreach (['fx_realized_gain' => test()->gain, 'fx_realized_loss' => test()->loss] as $role => $account) {
        mapRoleTo("{$role}", $account);
    }
}

function usdReceipt(string $total, string $rate, array $allocations = [])
{
    return docEntry('usd_receipt', array_filter(['party' => test()->customer, 'bank' => test()->usdBank, 'total' => $total, 'allocs' => $allocations ?: null]), ['currency' => 'USD', 'exchange_rate' => $rate]);
}

function openUsdInvoice(string $total = '1000', string $rate = '15000'): string
{
    docPosted(usdInvoice($total, $rate)->assertCreated()->json('data.id'));

    return (string) DB::table('sl_open_items')->where('kind', 'invoice')->orderByDesc('id')->value('id');
}

/** Functional balance of an account over all journals, debit less credit. */
function functionalBalance(string $accountId): float
{
    return (float) DB::table('gl_journal_lines')->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')->value('balance');
}

/** Asks for the reversal of a journal and returns the response of the request. */
function reverseRequest(string $journalId, array $body = [])
{
    return test()->actingAs(test()->preparer)->postJson(test()->url."/journals/{$journalId}/reverse", array_merge(['reversal_date' => '2026-03-20', 'reason' => 'Salah catat'], $body));
}

function usdInvoice(string $total, string $rate)
{
    return docEntry('usd_sale', ['party' => test()->customer, 'total' => $total], ['currency' => 'USD', 'exchange_rate' => $rate]);
}
