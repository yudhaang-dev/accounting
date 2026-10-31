<?php

// Suggested transaction types for everyday cash and credit transactions (TE-FR-01). Nothing here is applied
// to a company without a confirmed import. The user fills in who, how much and which cash account; the
// receivable or payable comes from the control account of the posting profile of the party, and the cash
// from the ledger account of the cash account. Only the expense account of a supplier bill is picked by
// the user, since the template cannot know the chart of accounts of a company. Every type needs the
// posting profile of its parties, with the mapping of `receivables_control` or `payables_control`.
// A party field accepts only the party roles that fit the type (ADR-0015), so the roles it names must exist
// and be active in the company before the type is imported. A template cannot name a posting profile, since
// profiles belong to a company: to keep one person's staff advance apart from a supplier payable, the
// company gives the route of a new version its own posting profile.
return [
    'key' => 'daily-operations',
    'name' => 'Transaksi harian',
    'version' => 1,
    'types' => [
        // Without an allocation, or for more than the invoices it names, the receipt stays open as an advance (SL-FR-04).
        [
            'key' => 'customer_receipt',
            'label' => 'Terima Pembayaran Pelanggan',
            'description' => 'Uang masuk dari pelanggan untuk melunasi faktur, atau sebagai uang muka.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan', 'allowed_roles' => ['customer']],
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
            'description' => 'Tagihan dari pemasok yang belum dibayar. Menambah utang pemasok.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok', 'allowed_roles' => ['supplier']],
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
            'description' => 'Pembayaran ke pemasok untuk melunasi tagihan.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok', 'allowed_roles' => ['supplier']],
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
            'description' => 'Pelunasan faktur valas yang dibayar ke rekening berbeda mata uang.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan', 'allowed_roles' => ['customer']],
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
            'description' => 'Pembayaran tagihan valas dari rekening berbeda mata uang.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'supplier', 'type' => 'party', 'label' => 'Pemasok', 'allowed_roles' => ['supplier']],
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
            'description' => 'Uang keluar yang harus dikembalikan penerimanya, seperti kasbon karyawan.',
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
            'description' => 'Uang masuk dari pinjaman yang harus dilunasi, seperti pinjaman bank.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'party', 'type' => 'party', 'label' => 'Pemberi pinjaman', 'allowed_roles' => ['lender']],
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
        // The staff advance, its return, and the capital a shareholder has subscribed but not yet paid. Each
        // is a receivable from a party of one role; the roles keep an employee out of a shareholder's list.
        [
            'key' => 'employee_advance',
            'label' => 'Kasbon Karyawan',
            'description' => 'Uang keluar untuk karyawan yang harus dikembalikan, dipotong gaji atau dibayar kembali.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'employee', 'type' => 'party', 'label' => 'Karyawan', 'allowed_roles' => ['employee']],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'advance_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo pengembalian', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'employee'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'loan',
                'control_role' => 'receivables_control',
                'party_field' => 'employee',
                'document_number_field' => 'advance_number',
                'due_date_field' => 'due_date',
                'cash_account_field' => 'bank',
            ],
        ],
        [
            'key' => 'employee_advance_return',
            'label' => 'Pengembalian Kasbon Karyawan',
            'description' => 'Uang masuk dari karyawan untuk melunasi kasbon.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'employee', 'type' => 'party', 'label' => 'Karyawan', 'allowed_roles' => ['employee']],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah diterima'],
                ['name' => 'allocations', 'type' => 'allocations', 'label' => 'Kasbon yang dilunasi', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'employee'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'payment',
                'control_role' => 'receivables_control',
                'party_field' => 'employee',
                'document_number_field' => 'receipt_number',
                'due_date_field' => null,
                'cash_account_field' => 'bank',
                'allocations_field' => 'allocations',
            ],
        ],
        [
            'key' => 'capital_subscription',
            'label' => 'Modal Ditempatkan, Belum Disetor',
            'description' => 'Modal yang dijanjikan pemegang saham tetapi belum dibayar. Menambah piutang pemegang saham.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'shareholder', 'type' => 'party', 'label' => 'Pemegang saham', 'allowed_roles' => ['shareholder']],
                ['name' => 'subscription_number', 'type' => 'reference', 'label' => 'Nomor akta atau bukti', 'required' => false],
                ['name' => 'due_date', 'type' => 'date', 'label' => 'Jatuh tempo setoran', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
                ['name' => 'equity_account', 'type' => 'account', 'label' => 'Akun modal'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'shareholder'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'equity_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'invoice',
                'control_role' => 'receivables_control',
                'party_field' => 'shareholder',
                'document_number_field' => 'subscription_number',
                'due_date_field' => 'due_date',
                'cash_account_field' => null,
            ],
        ],
        [
            'key' => 'capital_payment',
            'label' => 'Setoran Modal Pemegang Saham',
            'description' => 'Uang masuk dari pemegang saham untuk modal yang sudah ditempatkan.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'shareholder', 'type' => 'party', 'label' => 'Pemegang saham', 'allowed_roles' => ['shareholder']],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah diterima'],
                ['name' => 'allocations', 'type' => 'allocations', 'label' => 'Modal yang dilunasi', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'shareholder'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'payment',
                'control_role' => 'receivables_control',
                'party_field' => 'shareholder',
                'document_number_field' => 'receipt_number',
                'due_date_field' => null,
                'cash_account_field' => 'bank',
                'allocations_field' => 'allocations',
            ],
        ],
        [
            'key' => 'loan_repayment',
            'label' => 'Bayar Pinjaman',
            'description' => 'Pembayaran kepada pemberi pinjaman untuk melunasi pinjaman yang diterima.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'party', 'type' => 'party', 'label' => 'Pemberi pinjaman', 'allowed_roles' => ['lender']],
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'payment_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah dibayar'],
                ['name' => 'allocations', 'type' => 'allocations', 'label' => 'Pinjaman yang dilunasi', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'party'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'payment',
                'control_role' => 'payables_control',
                'party_field' => 'party',
                'document_number_field' => 'payment_number',
                'due_date_field' => null,
                'cash_account_field' => 'bank',
                'allocations_field' => 'allocations',
            ],
        ],
        // Both amounts are asked for so that a transfer between accounts of different currencies can state each
        // in the currency of its account; for the same currency they are the same number.
        [
            'key' => 'cash_transfer',
            'label' => 'Transfer Antar Kas atau Bank',
            'description' => 'Memindahkan uang antar kas atau rekening bank milik sendiri.',
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
        // Cash with no party (ADR-0016): the route records only the cash, so the account of the other side is picked
        // by the user. The cash account takes the source `subledger`, so the account picked must accept it too.
        [
            'key' => 'cash_expense',
            'label' => 'Biaya Tunai',
            'description' => 'Biaya yang dibayar langsung dari kas atau rekening bank, tanpa tagihan pemasok.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Dibayar dari rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah'],
                ['name' => 'expense_account', 'type' => 'account', 'label' => 'Akun biaya'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'expense_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'cash',
                'cash_account_field' => 'bank',
                'document_number_field' => 'receipt_number',
            ],
        ],
        [
            'key' => 'other_cash_receipt',
            'label' => 'Penerimaan Tunai Lain',
            'description' => 'Uang masuk ke kas atau rekening bank tanpa pihak: pendapatan tunai, bunga bank yang diterima, atau setoran modal tunai.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Diterima di rekening'],
                ['name' => 'receipt_number', 'type' => 'reference', 'label' => 'Nomor bukti', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah diterima'],
                ['name' => 'income_account', 'type' => 'account', 'label' => 'Akun pendapatan atau ekuitas'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'income_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'cash',
                'cash_account_field' => 'bank',
                'document_number_field' => 'receipt_number',
            ],
        ],
        [
            'key' => 'bank_charge',
            'label' => 'Biaya atau Bunga Bank',
            'description' => 'Biaya admin, pajak bunga, atau bunga pinjaman yang dipotong bank. Dicatat sesuai baris rekening koran.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'bank', 'type' => 'cash_account', 'label' => 'Rekening bank'],
                ['name' => 'statement_reference', 'type' => 'reference', 'label' => 'Referensi rekening koran', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Jumlah dipotong'],
                ['name' => 'expense_account', 'type' => 'account', 'label' => 'Akun biaya atau bunga'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'expense_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'cash_account', 'field' => 'bank'], 'apply_dimensions' => false, 'reuses_field' => true],
            ],
            'subledger_route' => [
                'kind' => 'cash',
                'cash_account_field' => 'bank',
                'document_number_field' => 'statement_reference',
            ],
        ],
    ],
];
