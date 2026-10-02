<?php

// Pesan Jenis Transaksi dan entri (TE-FR-01, TE-FR-07).
return [

    'type_key_taken' => 'Key Jenis Transaksi :key sudah dipakai di perusahaan ini.',
    'type_unknown' => 'Jenis Transaksi :key tidak ada di perusahaan ini.',
    'version_unknown' => 'Versi :version Jenis Transaksi ini tidak ada.',
    'version_unpublished' => 'Versi :version Jenis Transaksi ini belum terbit.',
    'fields_required' => 'Versi terstruktur memerlukan minimal satu field.',
    'fields_not_allowed' => 'Versi baris bebas tidak memiliki field.',
    'field_name_taken' => 'Nama field :name dipakai lebih dari sekali.',
    'field_account_unknown' => 'Akun :id tidak ada di perusahaan ini.',
    'field_dimension_type_unknown' => 'Tipe dimensi tidak ada atau tidak aktif di perusahaan ini.',
    'rules_required' => 'Versi terstruktur membutuhkan sedikitnya satu aturan posting.',
    'rules_not_allowed' => 'Versi baris bebas tidak memiliki aturan posting.',
    'rule_side_required' => 'Aturan memposting ke sisi debit atau kredit.',
    'rule_amount_field_unknown' => 'Field nominal pada aturan harus berupa field amount dari versi ini.',
    'rule_account_field_unknown' => 'Field akun pada aturan harus berupa field account dari versi ini.',
    'rule_account_required' => 'Aturan membutuhkan akun: akun tetap atau field akun.',
    'rule_fx_once' => 'Satu versi paling banyak memiliki satu aturan selisih kurs.',
    'rule_reuse_undeclared' => 'Aturan ini memakai field yang juga dipakai aturan lain; setiap aturan yang berbagi field harus menyatakannya dengan reuses_field.',
    'lines_required' => 'Entri baris bebas membutuhkan baris.',
    'lines_not_allowed' => 'Entri terstruktur berisi nilai, bukan baris; barisnya berasal dari aturan posting.',
    'values_not_allowed' => 'Entri baris bebas berisi baris, bukan nilai field.',
    'value_required' => 'Field :field wajib diisi.',
    'value_invalid' => 'Nilai field :field tidak valid.',
    'value_unknown' => 'Versi tidak memiliki field :field.',
    'value_account_not_allowed' => 'Akun pada field :field bukan salah satu akun yang diizinkan.',
    'invalid_transition' => 'Aksi tidak berlaku untuk entri yang berstatus :status.',
    'line_outside_scope' => 'Baris ini di luar cakupan data Anda: baris harus memuat nilai untuk setiap dimensi yang membatasi Anda, di dalam cakupan Anda.',

];
