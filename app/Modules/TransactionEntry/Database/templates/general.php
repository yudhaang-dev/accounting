<?php

// Suggested transaction types (TE-FR-01). Nothing here is applied to a company without a confirmed import.
// The types use no account of a company: free-lines types let the user pick the accounts, and the
// structured one takes them as account fields, so the template fits any chart of accounts.
return [
    'key' => 'general',
    'name' => 'Jenis Transaksi umum',
    'version' => 1,
    'types' => [
        [
            'key' => 'general_journal',
            'label' => 'Jurnal Umum',
            'mode' => 'free_lines',
            'fields' => [],
            'posting_rules' => [],
        ],
        [
            'key' => 'adjustment',
            'label' => 'Jurnal Penyesuaian',
            'mode' => 'free_lines',
            'fields' => [],
            'posting_rules' => [],
        ],
        [
            'key' => 'payroll_summary',
            'label' => 'Ringkasan Payroll',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'payroll_date', 'type' => 'date', 'label' => 'Tanggal payroll'],
                ['name' => 'gross_salary', 'type' => 'amount', 'label' => 'Total gaji bruto'],
                ['name' => 'expense_account', 'type' => 'account', 'label' => 'Akun beban gaji'],
                ['name' => 'payable_account', 'type' => 'account', 'label' => 'Akun utang gaji'],
                ['name' => 'note', 'type' => 'text', 'label' => 'Catatan', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'gross_salary', 'account' => ['source' => 'field', 'field' => 'expense_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'gross_salary', 'account' => ['source' => 'field', 'field' => 'payable_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
        ],
        // The receivable is the control account of the customer's posting profile, so the type needs
        // the mapping of `receivables_control` and a party; it opens the invoice as an open item (SL-FR-04).
        [
            'key' => 'credit_sale',
            'label' => 'Penjualan Kredit',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan'],
                ['name' => 'invoice_number', 'type' => 'reference', 'label' => 'Nomor faktur', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total faktur'],
                ['name' => 'revenue_account', 'type' => 'account', 'label' => 'Akun pendapatan'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'customer'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'revenue_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'invoice',
                'control_role' => 'receivables_control',
                'party_field' => 'customer',
                'document_number_field' => 'invoice_number',
                'due_date_field' => 'due_date',
                'cash_account_field' => null,
            ],
        ],
    ],
];
