<?php

// Suggested transaction types for a food and beverage business (TE-FR-01). Nothing here is applied to a company
// without a confirmed import. The takings of a shift are one cash entry without a party (ADR-0016), and the
// takings of a delivery platform wait on a receivable until the platform pays. The user picks the revenue
// account, since the template cannot know the chart of accounts of a company.
return [
    'key' => 'fnb-operations',
    'name' => 'Transaksi makanan dan minuman',
    'version' => 1,
    'types' => [
        [
            'key' => 'fnb_daily_sales',
            'label' => 'Penjualan Harian Kedai',
            'description' => 'Rekap penjualan kasir yang diterima tunai, kartu, atau QRIS pada hari atau shift itu. Satu entri per rekap.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor rekap kasir', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total penjualan'],
                ['name' => 'revenue_account', 'type' => 'account', 'label' => 'Akun penjualan'],
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
            'key' => 'delivery_platform_sale',
            'label' => 'Penjualan Lewat Platform Pesan-Antar',
            'description' => 'Penjualan lewat aplikasi pesan-antar yang belum dicairkan. Menambah piutang platform; pencairannya dicatat sebagai Terima Pembayaran Pelanggan.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'platform', 'type' => 'party', 'label' => 'Platform', 'allowed_roles' => ['customer']],
                ['name' => 'statement_number', 'type' => 'reference', 'label' => 'Nomor laporan platform', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Nilai penjualan'],
                ['name' => 'revenue_account', 'type' => 'account', 'label' => 'Akun penjualan'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'platform'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'revenue_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'invoice',
                'control_role' => 'receivables_control',
                'party_field' => 'platform',
                'document_number_field' => 'statement_number',
                'due_date_field' => null,
                'cash_account_field' => null,
            ],
        ],
    ],
];
