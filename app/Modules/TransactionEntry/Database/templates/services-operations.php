<?php

// Suggested transaction types for a service company (TE-FR-01). Nothing here is applied to a company without a
// confirmed import. The user picks the revenue account, since the template cannot know the chart of accounts of
// a company. A customer's advance is the existing "Terima Pembayaran Pelanggan" left without an allocation.
return [
    'key' => 'services-operations',
    'name' => 'Transaksi jasa',
    'version' => 1,
    'types' => [
        [
            'key' => 'service_invoice',
            'label' => 'Faktur Jasa',
            'description' => 'Tagihan jasa kepada pelanggan yang belum dibayar. Menambah piutang pelanggan.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan', 'allowed_roles' => ['customer']],
                ['name' => 'invoice_number', 'type' => 'reference', 'label' => 'Nomor faktur', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total faktur'],
                ['name' => 'revenue_account', 'type' => 'account', 'account_classes' => ['income'], 'label' => 'Akun pendapatan jasa'],
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
        [
            'key' => 'service_cash_receipt',
            'label' => 'Pendapatan Jasa Tunai',
            'description' => 'Jasa yang langsung dibayar di kas atau rekening, tanpa faktur.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah diterima'],
                ['name' => 'revenue_account', 'type' => 'account', 'account_classes' => ['income'], 'label' => 'Akun pendapatan jasa'],
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
    ],
];
