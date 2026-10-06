<?php

// Pesan definisi laporan (RP-FR-01, RP-FR-02).
return [

    'key_taken' => 'Sudah ada definisi laporan dengan kunci ini.',
    'unmapped_row' => 'Belum dipetakan ke baris',
    'unclassified_row' => 'Belum diklasifikasikan',
    'cash_mapping_missing' => 'Petakan peran cash_equivalents ke setidaknya satu akun sebelum menjalankan arus kas.',
    'classification_rows_count' => 'Klasifikasi memiliki 1 sampai :max baris.',
    'classification_row_key_invalid' => 'Kunci baris diawali huruf kecil dan hanya berisi huruf kecil, angka, dan garis bawah; "unclassified" dicadangkan.',
    'classification_rules_count' => 'Klasifikasi memiliki 1 sampai :max aturan.',
    'classification_rule_row_unknown' => 'Aturan merujuk ke salah satu baris klasifikasi.',
    'classification_type_keys_invalid' => 'Key Jenis Transaksi adalah daftar paling banyak 50 key dengan huruf kecil, angka, titik, dan garis bawah.',
    'classification_components_count' => 'Laporan perubahan ekuitas memiliki 1 sampai :max komponen.',
    'classification_component_key_invalid' => 'Key komponen diawali huruf kecil dan hanya berisi huruf kecil, angka, dan garis bawah; "unassigned" dicadangkan.',
    'equity_components_missing' => 'Tambahkan komponen pada klasifikasi sebelum menjalankan laporan perubahan ekuitas.',
    'unassigned_component' => 'Belum ditetapkan',

    'basis_invalid' => 'Dasar nilai harus salah satu dari: :allowed.',
    'rows_count' => 'Laporan memiliki 1 sampai :max baris.',
    'row_key_invalid' => 'Kunci baris diawali huruf kecil dan hanya berisi huruf kecil, angka, dan garis bawah; "unmapped" dicadangkan.',
    'row_key_repeated' => 'Kunci baris ":key" muncul lebih dari satu kali.',
    'row_label_invalid' => 'Baris memerlukan label paling banyak 255 karakter.',
    'row_type_invalid' => 'Jenis baris harus salah satu dari: :allowed.',
    'earnings_row_repeated' => 'Laba tahun berjalan hanya boleh disajikan pada satu baris.',
    'sign_invalid' => 'Tanda harus debit atau kredit.',
    'selectors_required' => 'Baris akun memerlukan paling sedikit satu pemilih akun.',
    'selector_type_invalid' => 'Pemilih adalah akun, akun induk, atau kelas.',
    'account_unknown' => 'Akun tidak ada di perusahaan ini.',
    'class_invalid' => 'Kelas harus asset, liability, equity, income, atau expense.',
    'account_claimed' => 'Akun :code sudah ada di baris ":row".',
    'terms_required' => 'Subtotal memerlukan paling sedikit satu suku.',
    'term_row_unknown' => 'Suku merujuk baris di atasnya, atau "unmapped".',
    'term_sign_invalid' => 'Tanda suku adalah 1 atau -1.',

    'columns_count' => 'Laporan memiliki 1 sampai :max kolom.',
    'column_key_invalid' => 'Kunci kolom diawali huruf kecil dan hanya berisi huruf kecil, angka, dan garis bawah.',
    'column_key_repeated' => 'Kunci kolom ":key" muncul lebih dari satu kali.',
    'column_label_invalid' => 'Kolom memerlukan label paling banyak 255 karakter.',
    'column_kind_invalid' => 'Jenis kolom harus salah satu dari: :allowed.',
    'column_ref_unknown' => 'Selisih merujuk kolom di atasnya.',
    'favorable_invalid' => 'Arah menguntungkan adalah `higher` atau `lower`.',
    'plan_span_invalid' => 'Kolom rencana menjumlahkan rentang `period` atau `ytd`.',
    'plan_version_invalid' => 'Versi rencana adalah ID 26 karakter.',
    'shift_invalid' => 'Pergeseran adalah bilangan bulat tahun dan bulan, nol atau negatif.',

];
