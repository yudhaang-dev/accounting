<?php

// The manufacturing pack: the general pack with the chart variant and the transaction types of a light manufacturing company
// (ADR-0019). It names the template of each module by key and holds no content of its own. Released against the references of ADR-0020 (`reviewed`).
return [
    'key' => 'manufacturing',
    'name' => 'Manufaktur Ringan',
    'description' => 'Bagan akun manufaktur ringan (bahan baku, barang dalam proses, barang jadi, biaya produksi), peran pihak, jurnal produksi dan transaksi harian, serta laba rugi, neraca, dan arus kas.',
    'version' => 1,
    'reviewed' => true,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'manufacturing-id'],
        ['id' => 'account_posting', 'kind' => 'account_posting', 'template' => 'general-id'],
        ['id' => 'party_roles', 'kind' => 'party_roles', 'template' => 'default'],
        ['id' => 'account_roles', 'kind' => 'account_roles', 'template' => 'general-id'],
        ['id' => 'posting_profiles', 'kind' => 'posting_profiles', 'template' => 'general-id'],
        ['id' => 'cash_equivalents', 'kind' => 'cash_equivalents', 'template' => 'general-id'],
        ['id' => 'transaction_types.general', 'kind' => 'transaction_types', 'template' => 'general'],
        ['id' => 'transaction_types.daily-operations', 'kind' => 'transaction_types', 'template' => 'daily-operations'],
        ['id' => 'transaction_types.manufacturing-operations', 'kind' => 'transaction_types', 'template' => 'manufacturing-operations'],
        ['id' => 'report_definitions', 'kind' => 'report_definitions', 'template' => 'general-id'],
    ],
    // Small company without an accountant (ADR-0019 item 6): no free-form journal type; the optional accounts are left out by the install.
    'profiles' => [
        'simple' => [
            'components' => ['chart_of_accounts', 'account_posting', 'party_roles', 'account_roles', 'posting_profiles', 'cash_equivalents', 'transaction_types.daily-operations', 'transaction_types.manufacturing-operations', 'report_definitions'],
            // Optional accounts the report definitions name, which the profile installs anyway.
            'keep' => ['chart_of_accounts' => ['2160', '2170']],
        ],
    ],
];
