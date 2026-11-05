<?php

// Suggested transaction types for a contractor or project company (TE-FR-01). Nothing here is applied to a company
// without a confirmed import. The user picks the revenue account, since the template cannot know the chart of
// accounts of a company. Material and subcontractor bills are the existing "Tagihan Pemasok"; the project is a
// dimension on the entry.
return [
    'key' => 'contractor-operations',
    'name' => 'Transaksi kontraktor atau proyek',
    'version' => 1,
    'types' => [
        [
            'key' => 'progress_invoice',
            'label' => 'Tagihan Termin Proyek',
            'description' => 'Tagihan termin atau progres pekerjaan kepada pemberi kerja yang belum dibayar. Menambah piutang pemberi kerja.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pemberi kerja', 'allowed_roles' => ['customer']],
                ['name' => 'invoice_number', 'type' => 'reference', 'label' => 'Nomor tagihan', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Nilai tagihan'],
                ['name' => 'revenue_account', 'type' => 'account', 'label' => 'Akun pendapatan kontrak'],
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
            'key' => 'project_cost_cash',
            'label' => 'Biaya Proyek Tunai',
            'description' => 'Biaya proyek yang dibayar langsung dari kas atau rekening, seperti upah harian atau material eceran.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
                ['name' => 'cost_account', 'type' => 'account', 'label' => 'Akun biaya proyek'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'cost_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'cash',
                'cash_account_field' => 'bank',
                'document_number_field' => 'receipt_number',
            ],
        ],
    ],
];
