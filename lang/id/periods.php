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
    'start_month_required' => 'Kalender bulanan memerlukan bulan awal tahun buku.',
    'start_month_monthly_only' => 'Hanya kalender bulanan yang memiliki bulan awal; pola lain dimulai pada tanggal tahun buku pertama.',
    'periods_required' => 'Kalender rentang bebas memerlukan periode-periode tahun buku.',
    'periods_only_free' => 'Periode hanya dapat diberikan untuk kalender rentang bebas.',
    'free_single_year' => 'Kalender rentang bebas membuat satu tahun buku sekali jalan, dengan periode yang Anda berikan.',
    'extra_week_only' => 'Minggu tambahan hanya untuk satu tahun buku 4-4-5.',
    'period_end_before_start' => 'Periode tidak dapat berakhir sebelum dimulai.',
    'period_not_contiguous' => 'Periode harus berurutan tanpa celah; periode ini harus dimulai pada :date.',
    'invalid_transition' => 'Periode yang berstatus :from tidak dapat menjadi :to.',
    'not_ready' => 'Kalender fiskal belum siap: :problems.',

    'problems' => [
        'calendar_missing' => 'kalender fiskal belum diatur',
        'no_fiscal_year' => 'belum ada tahun buku',
        'no_open_period' => 'tidak ada periode yang terbuka',
    ],

];
