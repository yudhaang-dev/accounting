<?php

namespace Database\Seeders\Demo;

/**
 * Transaction types, numbering and the approval policy (PANDUAN-AKUNTAN-SETUP 11 and 12). Templates are
 * imported as an accountant would, with confirmation; the types the templates cannot know (sales and
 * purchases with VAT and dimensions, payroll, VAT settlement) are designed here with fixed accounts, so the
 * finance admin only fills in who, how much, and for which branch.
 */
final class DemoCatalog
{
    public function __construct(private readonly DemoFoundation $f) {}

    public function run(): void
    {
        $this->f->say('Jenis transaksi: impor template dan rancangan sendiri');

        $api = $this->f->api->as($this->f->users['accountant']);
        $api->post($this->f->url('/transaction-types/import-template'), ['template' => 'general', 'confirmed' => true, 'keys' => ['general_journal', 'adjustment']]);
        $api->post($this->f->url('/transaction-types/import-template'), ['template' => 'daily-operations', 'confirmed' => true, 'keys' => ['customer_receipt', 'supplier_payment', 'cash_transfer']]);

        $this->define('sales_invoice', 'Faktur Penjualan', $this->salesInvoice());
        $this->define('purchase_invoice', 'Faktur Pembelian', $this->purchaseInvoice());
        $this->define('payroll', 'Gaji Bulanan', $this->payroll());
        $this->define('vat_settlement', 'Penyelesaian PPN', $this->vatSettlement());
        $this->define('erp_sales', 'Penjualan dari ERP', $this->erpSales());

        $this->numbering();
        $this->approvalPolicy();
    }

    private function fixed(string $code): array
    {
        return ['source' => 'fixed', 'account_id' => $this->f->account($code)];
    }

    private function control(string $partyField): array
    {
        return ['source' => 'control_account', 'party_field' => $partyField];
    }

    /** @return array<string, mixed> */
    private function salesInvoice(): array
    {
        return [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan'],
                ['name' => 'invoice_number', 'type' => 'reference', 'label' => 'Nomor faktur', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
                ['name' => 'branch', 'type' => 'dimension', 'label' => 'Cabang', 'dimension_type' => 'branch'],
                ['name' => 'department', 'type' => 'dimension', 'label' => 'Departemen', 'dimension_type' => 'department', 'required' => false],
                ['name' => 'subtotal', 'type' => 'amount', 'label' => 'Nilai sebelum PPN'],
                ['name' => 'ppn', 'type' => 'amount', 'label' => 'PPN 11%', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total faktur'],
                ['name' => 'revenue_account', 'type' => 'account', 'label' => 'Jenis pendapatan', 'account_ids' => [$this->f->account('4110'), $this->f->account('4120')]],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => $this->control('customer'), 'apply_dimensions' => true, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'subtotal', 'account' => ['source' => 'field', 'field' => 'revenue_account'], 'apply_dimensions' => true, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'ppn', 'account' => $this->fixed('2151'), 'apply_dimensions' => true, 'reuses_field' => false],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'receivables_control', 'party_field' => 'customer', 'document_number_field' => 'invoice_number', 'due_date_field' => 'due_date', 'cash_account_field' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function purchaseInvoice(): array
    {
        return [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok'],
                ['name' => 'bill_number', 'type' => 'reference', 'label' => 'Nomor tagihan', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
                ['name' => 'department', 'type' => 'dimension', 'label' => 'Departemen', 'dimension_type' => 'department', 'required' => false],
                ['name' => 'subtotal', 'type' => 'amount', 'label' => 'Nilai sebelum PPN'],
                ['name' => 'ppn', 'type' => 'amount', 'label' => 'PPN masukan', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total tagihan'],
                ['name' => 'expense_account', 'type' => 'account', 'label' => 'Akun biaya atau persediaan'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'subtotal', 'account' => ['source' => 'field', 'field' => 'expense_account'], 'apply_dimensions' => true, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'ppn', 'account' => $this->fixed('1141'), 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => $this->control('supplier'), 'apply_dimensions' => false, 'reuses_field' => false],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control', 'party_field' => 'supplier', 'document_number_field' => 'bill_number', 'due_date_field' => 'due_date', 'cash_account_field' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function payroll(): array
    {
        return [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'payroll', 'type' => 'party', 'label' => 'Penerima gaji'],
                ['name' => 'reference', 'type' => 'reference', 'label' => 'Nomor payroll', 'required' => false],
                ['name' => 'pay_date', 'type' => 'date', 'label' => 'Tanggal bayar', 'required' => false],
                ['name' => 'department', 'type' => 'dimension', 'label' => 'Departemen', 'dimension_type' => 'department', 'required' => false],
                ['name' => 'gross', 'type' => 'amount', 'label' => 'Total gaji dan tunjangan'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'gross', 'account' => $this->fixed('6210'), 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'gross', 'account' => $this->control('payroll'), 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control', 'party_field' => 'payroll', 'document_number_field' => 'reference', 'due_date_field' => 'pay_date', 'cash_account_field' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function vatSettlement(): array
    {
        return [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'tax_office', 'type' => 'party', 'label' => 'Kantor pajak'],
                ['name' => 'reference', 'type' => 'reference', 'label' => 'Nomor SSP', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Batas setor', 'required' => false],
                ['name' => 'output', 'type' => 'amount', 'label' => 'PPN keluaran'],
                ['name' => 'input', 'type' => 'amount', 'label' => 'PPN masukan'],
                ['name' => 'net', 'type' => 'amount', 'label' => 'PPN kurang bayar'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'output', 'account' => $this->fixed('2151'), 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'input', 'account' => $this->fixed('1141'), 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'net', 'account' => $this->control('tax_office'), 'apply_dimensions' => false, 'reuses_field' => false],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'payables_control', 'party_field' => 'tax_office', 'document_number_field' => 'reference', 'due_date_field' => 'due_date', 'cash_account_field' => null],
        ];
    }

    /** The sale an ERP sends as a financial event: who, which branch, and the amounts. @return array<string, mixed> */
    private function erpSales(): array
    {
        return [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan'],
                ['name' => 'invoice_number', 'type' => 'reference', 'label' => 'Nomor faktur', 'required' => false],
                ['name' => 'branch', 'type' => 'dimension', 'label' => 'Cabang', 'dimension_type' => 'branch'],
                ['name' => 'subtotal', 'type' => 'amount', 'label' => 'Nilai sebelum PPN'],
                ['name' => 'ppn', 'type' => 'amount', 'label' => 'PPN 11%', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total faktur'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => $this->control('customer'), 'apply_dimensions' => true, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'subtotal', 'account' => $this->fixed('4110'), 'apply_dimensions' => true, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'ppn', 'account' => $this->fixed('2151'), 'apply_dimensions' => true, 'reuses_field' => false],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'receivables_control', 'party_field' => 'customer', 'document_number_field' => 'invoice_number', 'due_date_field' => null, 'cash_account_field' => null],
        ];
    }

    /** @param array<string, mixed> $body */
    private function define(string $key, string $label, array $body): void
    {
        $api = $this->f->api;
        $type = $api->post($this->f->url('/transaction-types'), ['key' => $key, 'label' => $label])['data'];
        $draft = $api->post($this->f->url("/transaction-types/{$type['id']}/versions"), $body)['data'];
        $version = (int) collect($draft['versions'])->max('version');
        $api->post($this->f->url("/transaction-types/{$type['id']}/versions/{$version}/publish"));
    }

    private function numbering(): void
    {
        $this->f->say('Penomoran dokumen per jenis');

        $types = collect($this->f->api->get($this->f->url('/transaction-types'))['data'])->keyBy('key');

        foreach ([
            'sales_invoice' => ['INV/{yyyy}/{mm}/{seq}', 4, 'monthly'],
            'purchase_invoice' => ['BILL/{yyyy}/{mm}/{seq}', 4, 'monthly'],
            'customer_receipt' => ['BKM/{yyyy}/{mm}/{seq}', 4, 'monthly'],
            'supplier_payment' => ['BKK/{yyyy}/{mm}/{seq}', 4, 'monthly'],
            'cash_transfer' => ['TRF/{yyyy}/{seq}', 4, 'yearly'],
            'payroll' => ['PAY/{yyyy}/{mm}/{seq}', 2, 'monthly'],
            'vat_settlement' => ['PPN/{yyyy}/{mm}/{seq}', 2, 'monthly'],
            'erp_sales' => ['ERP/{yyyy}/{mm}/{seq}', 4, 'monthly'],
            'general_journal' => ['JU/{yyyy}/{mm}/{seq}', 4, 'monthly'],
            'adjustment' => ['JP/{yyyy}/{mm}/{seq}', 4, 'monthly'],
        ] as $key => [$pattern, $padding, $reset]) {
            $this->f->api->put($this->f->url("/transaction-types/{$types[$key]['id']}/numbering"), [
                'pattern' => $pattern, 'padding' => $padding, 'reset' => $reset, 'policy' => 'on_submit',
            ]);
        }
    }

    private function approvalPolicy(): void
    {
        $this->f->say('Kebijakan persetujuan: pemisahan tugas, dua tahap untuk nilai besar');

        $this->f->api->as($this->f->users['owner'])->put($this->f->url('/approval-policy'), [
            'lock_version' => 0,
            'separation_of_duties' => true,
            'rules' => [
                // Entries made by schedules are posted by themselves once the schedule is set up and approved.
                ['transaction_type_key' => null, 'source' => 'recurring', 'threshold' => null, 'stages' => [], 'auto_post' => true],
                ['transaction_type_key' => 'general_journal', 'source' => null, 'threshold' => '100000000', 'stages' => ['entries.approve', 'entries.approve'], 'auto_post' => true],
                ['transaction_type_key' => 'adjustment', 'source' => null, 'threshold' => '100000000', 'stages' => ['entries.approve', 'entries.approve'], 'auto_post' => true],
                ['transaction_type_key' => null, 'source' => null, 'threshold' => '300000000', 'stages' => ['entries.approve', 'entries.approve'], 'auto_post' => true],
                ['transaction_type_key' => null, 'source' => null, 'threshold' => null, 'stages' => ['entries.approve'], 'auto_post' => true],
            ],
        ]);
    }
}
