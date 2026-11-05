<?php

// The contractor pack: the general pack with the chart variant and the transaction types of a contractor or project company
// (ADR-0019). It names the template of each module by key and holds no content of its own. Not released
// (`reviewed` false) until an accountant has gone through the accounts, the transaction types, and the reports.
return [
    'key' => 'contractor',
    'name' => 'Kontraktor atau Proyek',
    'description' => 'Bagan akun kontraktor atau proyek (piutang retensi, uang muka proyek, pendapatan kontrak, biaya material dan alat), peran pihak, tagihan termin dan transaksi harian, serta laba rugi, neraca, dan arus kas.',
    'version' => 1,
    'reviewed' => false,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'contractor-id'],
        ['id' => 'account_posting', 'kind' => 'account_posting', 'template' => 'general-id'],
        ['id' => 'party_roles', 'kind' => 'party_roles', 'template' => 'default'],
        ['id' => 'account_roles', 'kind' => 'account_roles', 'template' => 'general-id'],
        ['id' => 'posting_profiles', 'kind' => 'posting_profiles', 'template' => 'general-id'],
        ['id' => 'cash_equivalents', 'kind' => 'cash_equivalents', 'template' => 'general-id'],
        ['id' => 'transaction_types.general', 'kind' => 'transaction_types', 'template' => 'general'],
        ['id' => 'transaction_types.daily-operations', 'kind' => 'transaction_types', 'template' => 'daily-operations'],
        ['id' => 'transaction_types.contractor-operations', 'kind' => 'transaction_types', 'template' => 'contractor-operations'],
        ['id' => 'report_definitions', 'kind' => 'report_definitions', 'template' => 'general-id'],
    ],
];
