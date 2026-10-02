<?php

// Pesan bagan akun (GL-FR-01) dan impor template (X-AC-03).
return [

    'code_taken' => 'Kode akun :code sudah dipakai di perusahaan ini.',
    'parent_not_found' => 'Akun induk tidak ada di perusahaan ini.',
    'parent_inactive' => 'Akun induk nonaktif.',
    'parent_has_postings' => 'Akun induk sudah memiliki posting dan harus tetap menjadi akun daun.',
    'parent_class_mismatch' => 'Akun induk dan anaknya harus berkelas sama.',
    'parent_cycle' => 'Akun tidak dapat dipindahkan ke bawah dirinya sendiri atau turunannya.',
    'dimension_type_unknown' => 'Tipe dimensi :key tidak ada atau nonaktif.',
    'currency_required' => 'Batasan ke satu mata uang tertentu memerlukan mata uang yang dibatasi.',
    'currency_not_specific' => 'Mata uang yang dibatasi hanya dapat diisi bersama batasan "specific".',
    'currency_unknown' => 'Mata uang :code bukan mata uang ISO 4217.',
    'currency_inactive' => 'Mata uang :code belum diaktifkan untuk perusahaan ini.',
    'currency_is_functional' => 'Mata uang fungsional :code tidak dapat menjadi mata uang asing sebuah akun; gunakan batasan "functional_only".',
    'revaluable_functional_only' => 'Akun yang dibatasi ke mata uang fungsional tidak dapat ditandai untuk revaluasi.',
    'template_unknown' => 'Template bagan akun ini tidak ada.',
    'chart_not_empty' => 'Template hanya dapat diimpor ke bagan akun yang kosong.',
    'exclude_unknown' => 'Template tidak memiliki akun :code.',
    'exclude_not_optional' => 'Akun :code bukan akun opsional dan tidak dapat dilewati.',
    'missing_classes' => 'Bagan akun memerlukan setidaknya satu akun daun aktif untuk: :classes.',

    'classes' => [
        'asset' => 'aset',
        'liability' => 'liabilitas',
        'equity' => 'ekuitas',
        'income' => 'pendapatan',
        'expense' => 'beban',
    ],

];
