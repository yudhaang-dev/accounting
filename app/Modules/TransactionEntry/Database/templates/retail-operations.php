<?php

// Suggested transaction types for a trading or retail company (TE-FR-01). Nothing here is applied to a company
// without a confirmed import. As in the other templates, the accounts of the revenue, returns, and stock are
// picked by the user, since the template cannot know the chart of accounts of a company. The cash sale is a
// cash route without a party (ADR-0016); the returns are credit notes of the customer or the supplier.
return [
    'key' => 'retail-operations',
    'name' => 'Transaksi dagang atau ritel',
    'version' => 1,
    'types' => [
        [
            'key' => 'daily_cash_sale',
            'label' => 'Penjualan Tunai Harian',
            'description' => 'Penjualan kasir yang langsung diterima di kas atau rekening, tanpa faktur. Biasanya satu entri per hari atau per shift.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor rekap kasir', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total penjualan'],
                ['name' => 'revenue_account', 'type' => 'account', 'account_classes' => ['income'], 'label' => 'Akun penjualan'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'revenue_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'cash',
                'cash_account_field' => 'bank',
                'document_number_field' => 'receipt_number',
            ],
        ],
        [
            'key' => 'sales_return',
            'label' => 'Retur Penjualan',
            'description' => 'Barang yang dikembalikan pelanggan atas faktur yang belum dibayar. Mengurangi piutang pelanggan.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan', 'allowed_roles' => ['customer']],
                ['name' => 'credit_note_number', 'type' => 'reference', 'label' => 'Nomor nota retur', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Nilai retur'],
                ['name' => 'returns_account', 'type' => 'account', 'account_classes' => ['income'], 'label' => 'Akun retur penjualan'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'returns_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'customer'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'credit_note',
                'control_role' => 'receivables_control',
                'party_field' => 'customer',
                'document_number_field' => 'credit_note_number',
                'due_date_field' => null,
                'cash_account_field' => null,
            ],
        ],
        [
            'key' => 'purchase_return',
            'label' => 'Retur Pembelian',
            'description' => 'Barang yang dikembalikan ke pemasok atas tagihan yang belum dibayar. Mengurangi utang pemasok.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok', 'allowed_roles' => ['supplier']],
                ['name' => 'credit_note_number', 'type' => 'reference', 'label' => 'Nomor nota retur', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Nilai retur'],
                ['name' => 'returns_account', 'type' => 'account', 'account_classes' => ['asset', 'expense'], 'label' => 'Akun persediaan atau retur pembelian'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'supplier'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'returns_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'credit_note',
                'control_role' => 'payables_control',
                'party_field' => 'supplier',
                'document_number_field' => 'credit_note_number',
                'due_date_field' => null,
                'cash_account_field' => null,
            ],
        ],
    ],
];
