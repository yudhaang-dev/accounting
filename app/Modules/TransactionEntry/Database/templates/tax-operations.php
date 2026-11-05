<?php

// Suggested transaction types for tax (TE-FR-01). Nothing here is applied to a company without a confirmed import.
// This is not a tax engine (ADR-0019): the amount of tax is worked out outside the system and entered by the user,
// who also picks the accounts, since the template cannot know the chart of accounts of a company.
return [
    'key' => 'tax-operations',
    'name' => 'Transaksi pajak',
    'version' => 1,
    'types' => [
        [
            'key' => 'tax_accrual',
            'label' => 'Pengakuan Pajak',
            'description' => 'Mencatat pajak terutang satu masa, mis. PPh final atau PPN kurang bayar, sebagai beban dan utang pajak. Nilainya dihitung di luar sistem.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'tax_date', 'type' => 'date', 'label' => 'Tanggal'],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Pajak terutang'],
                ['name' => 'expense_account', 'type' => 'account', 'label' => 'Akun beban atau PPN masukan'],
                ['name' => 'payable_account', 'type' => 'account', 'label' => 'Akun utang pajak'],
                ['name' => 'note', 'type' => 'text', 'label' => 'Catatan', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'expense_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'payable_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
        ],
        [
            'key' => 'tax_payment',
            'label' => 'Setor Pajak',
            'description' => 'Penyetoran pajak dari kas atau rekening bank, mengurangi utang pajak.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti setor', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah disetor'],
                ['name' => 'payable_account', 'type' => 'account', 'label' => 'Akun utang pajak'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'payable_account'], 'apply_dimensions' => true, 'reuses_field' => true],
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
