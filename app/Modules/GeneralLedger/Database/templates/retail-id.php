<?php

/*
| Chart of accounts for a trading or retail company (suggestion only, P-01, X-AC-03). It extends `general-id`
| and lists only the accounts it adds, kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Varian jenis usaha". Every
| addition sits under a parent the report templates of `general-id` already select, so no statement gains an
| unmapped row. Stock is one account of goods, not stock per item (ADR-0019).
*/

$account = fn (string $code, string $name, string $class, ?string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'retail-id',
    'name' => 'Dagang atau Ritel Indonesia',
    'version' => 1,
    'extends' => 'general-id',

    'accounts' => [
        $account('1124', 'Piutang Marketplace dan Penyelesaian Pembayaran', 'asset', 'debit', '1120', false, 'Penjualan online atau kartu yang belum dicairkan'),
        $account('1133', 'Barang dalam Perjalanan', 'asset', 'debit', '1130', true, 'Opsional; pembelian yang belum diterima'),
        $account('4111', 'Penjualan Toko', 'income', 'credit', '4110', false, 'Daun'),
        $account('4112', 'Penjualan Online dan Marketplace', 'income', 'credit', '4110', false, 'Daun'),
        $account('4113', 'Penjualan Grosir', 'income', 'credit', '4110', true, 'Opsional; daun'),
        $account('5130', 'Biaya Angkut Pembelian', 'expense', 'debit', '5100', false, 'Daun'),
        $account('5140', 'Potongan dan Retur Pembelian', 'expense', 'credit', '5100', false, 'Kontra'),
        $account('6140', 'Komisi dan Biaya Layanan Marketplace', 'expense', 'debit', '6100', false, 'Daun'),
        $account('6150', 'Biaya Merchant Kartu dan QRIS', 'expense', 'debit', '6100', true, 'Opsional; daun'),
    ],
];
