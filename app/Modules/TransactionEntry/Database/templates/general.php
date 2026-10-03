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
    ],
];
