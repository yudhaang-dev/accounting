<?php

// Pesan kalender fiskal, tahun buku, dan periode (GL-FR-03, GL-FR-04).
return [

    'calendar_exists' => 'Perusahaan ini sudah memiliki kalender fiskal.',
    'calendar_locked' => 'Kalender fiskal tidak dapat diubah setelah tahun buku dibuat.',
    'calendar_required' => 'Atur kalender fiskal sebelum membuat tahun buku.',
    'start_date_required' => 'Tahun buku pertama memerlukan tanggal mulai.',
    'start_date_first_day' => 'Tahun buku pertama harus dimulai pada tanggal 1 bulan :month.',
    'start_date_continue' => 'Tahun buku baru menyambung tahun sebelumnya dan dimulai pada :date.',
    'name_taken' => 'Tahun buku bernama :name sudah ada.',
    'name_with_count' => 'Nama hanya dapat diberikan saat membuat satu tahun buku.',
    'not_ready' => 'Kalender fiskal belum siap: :problems.',

    'problems' => [
        'calendar_missing' => 'kalender fiskal belum diatur',
        'no_fiscal_year' => 'belum ada tahun buku',
        'no_open_period' => 'tidak ada periode yang terbuka',
    ],

];
