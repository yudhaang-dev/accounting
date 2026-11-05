<?php

/*
| Chart of accounts for a service company (suggestion only, P-01, X-AC-03). It extends `general-id` and lists
| only the accounts it adds, kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Varian jenis usaha". Every addition
| sits under a parent the report templates of `general-id` already select, so no statement gains an unmapped row.
*/

$account = fn (string $code, string $name, string $class, ?string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'services-id',
    'name' => 'Jasa Indonesia',
    'version' => 1,
    'extends' => 'general-id',

    'accounts' => [
        $account('1125', 'Tagihan Belum Diterbitkan', 'asset', 'debit', '1120', true, 'Opsional; jasa selesai yang belum ditagih'),
        $account('4121', 'Pendapatan Jasa Proyek', 'income', 'credit', '4120', false, 'Daun'),
        $account('4122', 'Pendapatan Jasa Berulang atau Retainer', 'income', 'credit', '4120', false, 'Daun'),
        $account('4123', 'Penggantian Biaya dari Pelanggan', 'income', 'credit', '4120', true, 'Opsional; daun'),
        $account('5250', 'Biaya Perjalanan dan Akomodasi Proyek', 'expense', 'debit', '5200', true, 'Opsional; daun'),
        $account('6295', 'Pelatihan dan Sertifikasi Staf', 'expense', 'debit', '6200', true, 'Opsional; daun'),
    ],
];
