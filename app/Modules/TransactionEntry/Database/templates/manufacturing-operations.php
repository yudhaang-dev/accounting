<?php

// Suggested transaction types for a light manufacturing company (TE-FR-01). Nothing here is applied to a company
// without a confirmed import. Raw material is the existing "Tagihan Pemasok" and the sale is "Penjualan Kredit";
// what is added is the journal that moves cost between the stock accounts, which an accountant fills in because
// the system keeps no stock per item (ADR-0019).
return [
    'key' => 'manufacturing-operations',
    'name' => 'Transaksi manufaktur ringan',
    'version' => 1,
    'types' => [
        [
            'key' => 'production_journal',
            'label' => 'Jurnal Produksi',
            'description' => 'Jurnal bebas untuk memindahkan biaya antar akun persediaan: bahan baku ke barang dalam proses, lalu ke barang jadi, dan ke HPP saat terjual. Nilainya dari catatan produksi di luar sistem. Untuk akuntan.',
            'mode' => 'free_lines',
            'fields' => [],
            'posting_rules' => [],
        ],
        [
            'key' => 'factory_cost_cash',
            'label' => 'Biaya Pabrik Tunai',
            'description' => 'Biaya produksi yang dibayar langsung dari kas atau rekening, seperti upah borongan atau listrik pabrik.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
                ['name' => 'cost_account', 'type' => 'account', 'label' => 'Akun biaya produksi'],
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
