<?php

/*
| Chart of accounts for a light manufacturing company (suggestion only, P-01, X-AC-03). It extends `general-id`
| and lists only the accounts it adds, kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Varian jenis usaha". Every
| addition sits under a parent the report templates of `general-id` already select (ADR-0019). Stock is one
| account per stage (raw material, work in process, finished goods), not stock per item; the cost of a run is
| moved between them by an adjusting journal.
*/

$account = fn (string $code, string $name, string $class, ?string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'manufacturing-id',
    'name' => 'Manufaktur Ringan Indonesia',
    'version' => 1,
    'extends' => 'general-id',

    'accounts' => [
        $account('1133', 'Persediaan Bahan Baku', 'asset', 'debit', '1130', false, 'Nilai dari sistem sumber [R4]'),
        $account('1134', 'Persediaan Barang dalam Proses', 'asset', 'debit', '1130', false, 'Nilai dari sistem sumber [R4]'),
        $account('1135', 'Persediaan Barang Jadi', 'asset', 'debit', '1130', false, 'Nilai dari sistem sumber [R4]'),
        $account('1215', 'Mesin dan Peralatan Produksi', 'asset', 'debit', '1210', true, 'Opsional'),
        $account('4114', 'Penjualan Produk Jadi', 'income', 'credit', '4110', false, 'Daun'),
        $account('4115', 'Penjualan Sisa Produksi', 'income', 'credit', '4110', true, 'Opsional; daun'),
        $account('5150', 'Pemakaian Bahan Baku', 'expense', 'debit', '5100', false, 'Daun'),
        $account('5160', 'Tenaga Kerja Langsung Produksi', 'expense', 'debit', '5100', false, 'Daun'),
        $account('5170', 'Overhead Pabrik', 'expense', 'debit', '5100', false, 'Daun'),
    ],
];
