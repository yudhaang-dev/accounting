<?php

/*
| Chart of accounts for a contractor or project company (suggestion only, P-01, X-AC-03). It extends `general-id`
| and lists only the accounts it adds, kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Varian jenis usaha". Every
| addition sits under a parent the report templates of `general-id` already select (ADR-0019). Work in progress
| is one account per kind of cost, not a project ledger; the project itself is a dimension.
*/

$account = fn (string $code, string $name, string $class, ?string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'contractor-id',
    'name' => 'Kontraktor atau Proyek Indonesia',
    'version' => 1,
    'extends' => 'general-id',

    'accounts' => [
        $account('1124', 'Piutang Retensi', 'asset', 'debit', '1120', false, 'Tahanan pemberi kerja sampai masa pemeliharaan selesai'),
        $account('1125', 'Tagihan Belum Diterbitkan', 'asset', 'debit', '1120', true, 'Opsional; pekerjaan selesai yang belum ditagih'),
        $account('1134', 'Pekerjaan dalam Proses', 'asset', 'debit', '1130', true, 'Opsional; biaya proyek yang belum diakui sebagai biaya'),
        $account('2141', 'Uang Muka Proyek dari Pemberi Kerja', 'liability', 'credit', '2140', false, 'Daun'),
        $account('2142', 'Utang Retensi kepada Subkontraktor', 'liability', 'credit', '2140', true, 'Opsional; daun'),
        $account('4124', 'Pendapatan Kontrak Proyek', 'income', 'credit', '4120', false, 'Daun'),
        $account('4125', 'Pendapatan Pekerjaan Tambah', 'income', 'credit', '4120', true, 'Opsional; daun'),
        $account('5260', 'Material Proyek', 'expense', 'debit', '5200', false, 'Daun'),
        $account('5270', 'Sewa dan Operasi Alat Proyek', 'expense', 'debit', '5200', false, 'Daun'),
        $account('5280', 'Upah Harian dan Mandor', 'expense', 'debit', '5200', true, 'Opsional; daun'),
    ],
];
