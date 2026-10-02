<?php

// Pesan posting jurnal (GL-FR-05).
return [

    'lines_required' => 'Jurnal memerlukan setidaknya dua baris.',
    'one_side' => 'Setiap baris harus berisi debit atau kredit lebih dari nol, bukan keduanya.',
    'amount_invalid' => 'Nilai bukan bilangan desimal positif yang valid.',
    'amount_scale' => 'Nilai memiliki lebih dari :scale angka desimal.',
    'currency_unsupported' => 'Untuk saat ini hanya mata uang fungsional :currency yang dapat diposting.',
    'policy_key_unknown' => 'Perusahaan tidak memiliki Jenis Transaksi dengan key :key.',
    'policy_permission_unknown' => 'Permission :permission tidak ada.',
    'period_missing' => 'Tidak ada periode fiskal yang mencakup :date.',
    'period_soft_closed' => 'Periode :period berstatus soft-closed dan hanya menerima posting yang disebut kebijakan buku besar.',
    'period_closed' => 'Periode :period berstatus :status dan tidak menerima posting.',
    'account_unknown' => 'Akun tidak ada di perusahaan ini.',
    'account_inactive' => 'Akun nonaktif.',
    'account_parent' => 'Akun ini adalah akun induk; posting ke salah satu akun anaknya.',
    'source_not_allowed' => 'Akun :account tidak menerima posting dari sumber ":source".',
    'currency_not_allowed' => 'Akun :account tidak menerima mata uang :currency.',
    'dimension_required' => 'Akun :account mewajibkan nilai untuk dimensi :type.',
    'dimension_forbidden' => 'Akun :account tidak mengizinkan dimensi :type.',
    'dimension_set_unknown' => 'Set dimensi tidak ada di perusahaan ini.',
    'role_unmapped' => 'Peran akun :key belum dipetakan ke akun.',
    'role_class_not_allowed' => 'Akun berkelas :class tidak dapat berperan di sini; yang diizinkan: :allowed.',
    'already_reversed' => 'Jurnal :number sudah dibalik oleh :reversal.',
    'reversal_of' => 'Pembalikan jurnal :number',
    'override_reason_required' => 'Alasan wajib diisi untuk memposting ke akun :account dari sumber ":source".',
    'unbalanced' => 'Jurnal tidak seimbang: debit :debit, kredit :credit.',

];
