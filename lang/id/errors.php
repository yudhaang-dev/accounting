<?php

return [

    'unauthenticated' => 'Autentikasi diperlukan.',
    'forbidden' => 'Anda tidak diizinkan melakukan tindakan ini.',
    'not_found' => 'Resource yang diminta tidak ditemukan.',
    'validation_failed' => 'Data yang diberikan tidak valid.',
    'server_error' => 'Terjadi kesalahan yang tidak terduga.',

    'codes' => [
        'SOURCE_TRANSACTION_CONFLICT' => 'Transaksi dengan kunci sumber ini sudah ada dengan isi yang berbeda.',
        'IDEMPOTENCY_KEY_REUSED' => 'Idempotency-Key ini sudah dipakai dengan isi permintaan yang berbeda.',
        'REQUEST_IN_PROGRESS' => 'Permintaan dengan Idempotency-Key ini masih diproses.',
        'VERSION_CONFLICT' => 'Resource telah diubah oleh pihak lain. Muat ulang lalu coba lagi.',
        'INVALID_STATE_TRANSITION' => 'Aksi ini tidak diizinkan pada status saat ini.',
        'PERIOD_NOT_OPEN' => 'Periode tidak menerima posting.',
        'ACCOUNT_NOT_POSTABLE' => 'Akun tidak dapat menerima posting.',
        'POSTING_SOURCE_NOT_ALLOWED' => 'Akun tidak menerima sumber posting ini.',
        'DIMENSION_REQUIRED' => 'Dimensi yang wajib diisi belum ada.',
        'DIMENSION_INVALID' => 'Nilai dimensi tidak valid.',
        'UNBALANCED_ENTRY' => 'Entri tidak seimbang dalam mata uang fungsional.',
        'EXCHANGE_RATE_NOT_FOUND' => 'Kurs tidak tersedia.',
        'TRANSACTION_TYPE_VERSION_RETIRED' => 'Versi Jenis Transaksi ini sudah dihentikan.',
        'MAPPING_REQUIRED' => 'Pemetaan atau kebijakan yang wajib belum dikonfigurasi.',
        'TRANSACTION_TYPE_NOT_ALLOWED' => 'Klien ini tidak diizinkan memakai Jenis Transaksi ini.',
        'SEGREGATION_OF_DUTIES' => 'Anda tidak dapat menyetujui entri Anda sendiri.',
        'TWO_FACTOR_REQUIRED' => 'Aksi ini memerlukan autentikasi dua faktor.',
        'UNAUTHENTICATED' => 'Autentikasi diperlukan.',
        'FORBIDDEN' => 'Anda tidak diizinkan melakukan tindakan ini.',
        'NOT_FOUND' => 'Resource yang diminta tidak ditemukan.',
        'CSRF_TOKEN_MISMATCH' => 'Token CSRF tidak ada atau tidak valid.',
        'TOO_MANY_REQUESTS' => 'Terlalu banyak permintaan. Mohon perlambat.',
        'SERVER_ERROR' => 'Terjadi kesalahan yang tidak terduga.',
    ],

];
