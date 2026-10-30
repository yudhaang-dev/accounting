<?php

// Suggested transaction types for everyday cash and credit transactions (TE-FR-01). Nothing here is applied
// to a company without a confirmed import. The user fills in who, how much and which cash account; the
// receivable or payable comes from the control account of the posting profile of the party, and the cash
// from the ledger account of the cash account. Only the expense account of a supplier bill is picked by
// the user, since the template cannot know the chart of accounts of a company. Every type needs the
// posting profile of its parties, with the mapping of `receivables_control` or `payables_control`.
return [
    'key' => 'daily-operations',
    'name' => 'Transaksi harian',
    'version' => 1,
    'types' => [
        // Without an allocation, or for more than the invoices it names, the receipt stays open as an advance (SL-FR-04).
        [
            'key' => 'customer_receipt',
            'label' => 'Terima Pembayaran Pelanggan',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan'],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah diterima'],
                ['name' => 'allocations', 'type' => 'allocations', 'label' => 'Faktur yang dilunasi', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'customer'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'payment',
                'control_role' => 'receivables_control',
                'party_field' => 'customer',
                'document_number_field' => 'receipt_number',
                'due_date_field' => null,
                'cash_account_field' => 'bank',
                'allocations_field' => 'allocations',
            ],
        ],
        [
            'key' => 'supplier_bill',
            'label' => 'Tagihan Pemasok',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok'],
                ['name' => 'bill_number', 'type' => 'reference', 'label' => 'Nomor tagihan', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total tagihan'],
                ['name' => 'expense_account', 'type' => 'account', 'label' => 'Akun biaya atau persediaan'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'expense_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'supplier'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'invoice',
                'control_role' => 'payables_control',
                'party_field' => 'supplier',
                'document_number_field' => 'bill_number',
                'due_date_field' => 'due_date',
                'cash_account_field' => null,
            ],
        ],
        [
            'key' => 'supplier_payment',
            'label' => 'Bayar Pemasok',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok'],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'payment_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah dibayar'],
                ['name' => 'allocations', 'type' => 'allocations', 'label' => 'Tagihan yang dilunasi', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'supplier'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'payment',
                'control_role' => 'payables_control',
                'party_field' => 'supplier',
                'document_number_field' => 'payment_number',
                'due_date_field' => null,
                'cash_account_field' => 'bank',
                'allocations_field' => 'allocations',
            ],
        ],
        // For a payment in a currency other than the one of the cash account, e.g. a USD invoice paid into a
        // rupiah account. The entry is in the currency of the invoice; the cash amount is what the account
        // received, in its own currency. What the two amounts do not explain is the exchange difference.
        [
            'key' => 'customer_receipt_cross_currency',
            'label' => 'Terima Pembayaran, Rekening Beda Mata Uang',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan'],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah faktur yang dilunasi (mata uang entri)'],
                ['name' => 'cash_amount', 'type' => 'amount', 'label' => 'Jumlah diterima (mata uang rekening)'],
                ['name' => 'allocations', 'type' => 'allocations', 'label' => 'Faktur yang dilunasi', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'cash_amount', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'customer'], 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'fx_difference', 'apply_dimensions' => false],
            ],
            'subledger_route' => [
                'kind' => 'payment',
                'control_role' => 'receivables_control',
                'party_field' => 'customer',
                'document_number_field' => 'receipt_number',
                'due_date_field' => null,
                'cash_account_field' => 'bank',
                'allocations_field' => 'allocations',
            ],
        ],
        [
            'key' => 'supplier_payment_cross_currency',
            'label' => 'Bayar Pemasok, Rekening Beda Mata Uang',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok'],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'payment_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah tagihan yang dilunasi (mata uang entri)'],
                ['name' => 'cash_amount', 'type' => 'amount', 'label' => 'Jumlah dibayar (mata uang rekening)'],
                ['name' => 'allocations', 'type' => 'allocations', 'label' => 'Tagihan yang dilunasi', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'supplier'], 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'cash_amount', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'fx_difference', 'apply_dimensions' => false],
            ],
            'subledger_route' => [
                'kind' => 'payment',
                'control_role' => 'payables_control',
                'party_field' => 'supplier',
                'document_number_field' => 'payment_number',
                'due_date_field' => null,
                'cash_account_field' => 'bank',
                'allocations_field' => 'allocations',
            ],
        ],
        // Cash that goes out and leaves the party owing the company, such as the advance of an employee or a loan
        // given; the party repays it with a receipt that names it. To keep what employees owe apart from trade
        // receivables, give their parties a posting profile whose receivables control is an account of its own.
        [
            'key' => 'party_loan',
            'label' => 'Kasbon atau Pinjaman ke Pihak',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'party', 'type' => 'party', 'label' => 'Penerima'],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'loan_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo pengembalian', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'party'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'loan',
                'control_role' => 'receivables_control',
                'party_field' => 'party',
                'document_number_field' => 'loan_number',
                'due_date_field' => 'due_date',
                'cash_account_field' => 'bank',
            ],
        ],
        // The other way round: cash comes in and the company owes the party, repaid with a payment that names it.
        [
            'key' => 'loan_received',
            'label' => 'Pinjaman Diterima',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'party', 'type' => 'party', 'label' => 'Pemberi pinjaman'],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'loan_number', 'type' => 'reference', 'label' => 'Nomor perjanjian', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo pelunasan', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'party'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'loan',
                'control_role' => 'payables_control',
                'party_field' => 'party',
                'document_number_field' => 'loan_number',
                'due_date_field' => 'due_date',
                'cash_account_field' => 'bank',
            ],
        ],
        // Both amounts are asked for so that a transfer between accounts of different currencies can state each
        // in the currency of its account; for the same currency they are the same number.
        [
            'key' => 'cash_transfer',
            'label' => 'Transfer Antar Kas atau Bank',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'from', 'type' => 'cash_account', 'label' => 'Dari rekening'],
                ['name' => 'to', 'type' => 'cash_account', 'label' => 'Ke rekening'],
                ['name' => 'amount_out', 'type' => 'amount', 'label' => 'Jumlah keluar'],
                ['name' => 'amount_in', 'type' => 'amount', 'label' => 'Jumlah masuk'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'amount_in', 'account' => ['source' => 'cash_account', 'field' => 'to'], 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'amount_out', 'account' => ['source' => 'cash_account', 'field' => 'from'], 'apply_dimensions' => false, 'reuses_field' => false],
                ['type' => 'fx_difference', 'apply_dimensions' => false],
            ],
            'subledger_route' => [
                'kind' => 'transfer',
                'cash_account_field' => 'from',
                'counterpart_cash_account_field' => 'to',
            ],
        ],
    ],
];
