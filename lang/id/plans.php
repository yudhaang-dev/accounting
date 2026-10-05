<?php

// Pesan rencana, versi rencana, dan baris rencana (PL-FR-01, PL-FR-02, PL-FR-03).
return [

    'fiscal_year_unknown' => 'Tahun buku tidak ada di perusahaan ini.',
    'name_taken' => 'Sudah ada rencana dengan nama ini untuk tahun buku tersebut.',
    'version_name_taken' => 'Sudah ada versi dengan nama ini di rencana ini.',

    'period_unknown' => 'Periode tidak ada di perusahaan ini.',
    'period_outside_year' => 'Periode berada di luar tahun buku rencana.',
    'account_unknown' => 'Akun tidak ada di perusahaan ini.',
    'account_inactive' => 'Akun :code nonaktif.',
    'account_parent' => 'Akun :code adalah akun induk; rencanakan akun daun.',
    'account_class' => 'Akun :code bukan akun pendapatan atau beban.',
    'amount_invalid' => 'Nilai harus berupa bilangan desimal dengan paling banyak :scale desimal.',
    'line_duplicate' => 'Periode, akun, dan dimensi yang sama sudah ada di baris :row.',

    'copy_same_version' => 'Versi tidak dapat disalin ke dirinya sendiri.',
    'copy_other_year' => 'Versi milik rencana tahun buku lain.',

    'csv_required' => 'Kirim CSV sebagai "file" multipart atau isi text/csv.',
    'csv_too_large' => 'Berkas CSV lebih besar dari 2 MB.',
    'csv_empty' => 'Berkas CSV tidak memiliki baris.',
    'csv_too_many' => 'Berkas CSV memiliki lebih dari :max baris.',
    'csv_header' => 'Baris pertama harus menamai kolom :columns; dimensi adalah kolom bernama dimension.<kunci tipe>.',
    'csv_column_unknown' => 'Kolom ":column" tidak dikenal.',
    'csv_period_unknown' => 'Tidak ada periode yang memuat tanggal ":value".',
    'csv_account_unknown' => 'Tidak ada akun dengan kode ":value".',
    'csv_dimension_unknown' => 'Tidak ada nilai dengan kode ":value" untuk tipe dimensi ini.',

];
