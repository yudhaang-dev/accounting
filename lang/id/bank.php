<?php

// Pesan impor dan pencocokan mutasi rekening koran (SL-FR-13).
return [

    'mapping_amount_columns' => 'Isi satu kolom jumlah, atau kolom uang masuk beserta kolom uang keluar.',
    'mapping_missing' => 'Simpan pemetaan kolom rekening kas ini sebelum mengimpor mutasi.',
    'statement_already_imported' => 'Berkas ini sudah pernah diimpor ke rekening kas ini.',
    'csv_unreadable' => 'Berkas tidak dapat dibaca.',
    'csv_empty' => 'Berkas tidak memuat baris mutasi.',
    'csv_too_many_rows' => 'Satu rekening koran paling banyak :max baris.',
    'csv_columns_missing' => 'Baris memiliki kurang dari :columns kolom; periksa pemisah dan pemetaan.',
    'csv_date_invalid' => 'Tanggal ":value" tidak sesuai format :format.',
    'csv_amount_invalid' => 'Jumlah ":value" bukan angka selain nol.',
    'csv_in_out_invalid' => 'Tepat satu dari uang masuk (":in") dan uang keluar (":out") harus berisi angka selain nol.',
    'line_already_matched' => 'Baris mutasi ini sudah dicocokkan.',
    'line_not_matched' => 'Baris mutasi ini belum dicocokkan.',
    'transaction_other_account' => 'Transaksi kas bukan milik rekening kas pada rekening koran ini.',
    'transaction_differs' => 'Transaksi kas harus berupa transaksi :direction sebesar :amount.',
    'transaction_already_matched' => 'Transaksi kas sudah dicocokkan dengan baris mutasi lain.',

];
