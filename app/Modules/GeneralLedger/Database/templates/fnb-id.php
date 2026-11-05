<?php

/*
| Chart of accounts for a food and beverage business (suggestion only, P-01, X-AC-03). It extends `general-id`
| and lists only the accounts it adds, kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Varian jenis usaha". Every
| addition sits under a parent the report templates of `general-id` already select (ADR-0019). Stock is food and
| packaging as two accounts, not stock per item.
*/

$account = fn (string $code, string $name, string $class, ?string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'fnb-id',
    'name' => 'Makanan dan Minuman Indonesia',
    'version' => 1,
    'extends' => 'general-id',

    'accounts' => [
        $account('1124', 'Piutang Platform Pesan-Antar', 'asset', 'debit', '1120', false, 'Penjualan lewat aplikasi yang belum dicairkan'),
        $account('1133', 'Persediaan Bahan Makanan dan Minuman', 'asset', 'debit', '1130', false, 'Nilai dari sistem sumber [R4]'),
        $account('1134', 'Persediaan Kemasan', 'asset', 'debit', '1130', true, 'Opsional'),
        $account('4116', 'Penjualan Makan di Tempat', 'income', 'credit', '4110', false, 'Daun'),
        $account('4117', 'Penjualan Bawa Pulang', 'income', 'credit', '4110', false, 'Daun'),
        $account('4118', 'Penjualan Pesan-Antar', 'income', 'credit', '4110', true, 'Opsional; daun'),
        $account('5150', 'Pemakaian Bahan Makanan dan Minuman', 'expense', 'debit', '5100', false, 'Daun'),
        $account('5160', 'Pemakaian Kemasan', 'expense', 'debit', '5100', true, 'Opsional; daun'),
        $account('6140', 'Komisi Platform Pesan-Antar', 'expense', 'debit', '6100', true, 'Opsional; daun'),
        $account('6150', 'Biaya Merchant Kartu dan QRIS', 'expense', 'debit', '6100', true, 'Opsional; daun'),
        $account('6296', 'Gas dan Bahan Bakar Dapur', 'expense', 'debit', '6200', false, 'Daun'),
    ],
];
