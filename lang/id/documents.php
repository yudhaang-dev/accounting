<?php

// Pesan dokumen dan open item subledger (SL-FR-04).
return [

    'party_unknown' => 'Pihak tidak ada di perusahaan ini.',
    'party_inactive' => 'Pihak :name tidak aktif.',
    'party_not_customer' => 'Pihak :name bukan pelanggan.',
    'party_not_supplier' => 'Pihak :name bukan pemasok.',
    'cash_account_required' => 'Dokumen berjenis :kind memerlukan rekening kas.',
    'cash_account_unknown' => 'Rekening kas tidak ada di perusahaan ini.',
    'cash_account_inactive' => 'Rekening kas :name tidak aktif.',
    'cash_account_not_allowed' => 'Dokumen berjenis :kind tidak menggerakkan kas dan tidak memakai rekening kas.',
    'due_before_document' => 'Jatuh tempo tidak boleh sebelum tanggal dokumen.',
    'control_line_missing' => 'Dokumen berjenis :kind memerlukan baris ke akun kontrol pihak.',
    'control_line_side' => 'Dokumen berjenis :kind diposting ke akun kontrol pihak di sisi :side.',
    'control_currency_mixed' => 'Semua baris ke akun kontrol pihak harus berada dalam satu mata uang.',
    'cash_line_missing' => 'Dokumen memerlukan baris ke akun rekening kas :name.',
    'cash_line_side' => 'Dokumen berjenis :kind diposting ke rekening kas di sisi :side.',
    'cash_line_currency' => 'Baris ke rekening kas :name harus dalam mata uangnya, :currency.',
    'stray_control_line' => 'Akun :account adalah akun kontrol sebuah profil posting; hanya dokumen pihak tersebut yang dapat memposting ke sana.',
    'stray_cash_line' => 'Akun :account milik sebuah rekening kas; hanya dokumen yang menggerakkan kasnya yang dapat memposting ke sana.',

];
