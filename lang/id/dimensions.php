<?php

// Pesan tipe dan nilai dimensi (MD-FR-02, MD-FR-03).
return [

    'key_taken' => 'Tipe dimensi :key sudah ada di perusahaan ini.',
    'type_not_found' => 'Tipe dimensi tidak ada di perusahaan ini.',
    'type_inactive' => 'Tipe dimensi nonaktif.',
    'code_taken' => 'Kode :code sudah dipakai di tipe dimensi ini.',
    'type_not_hierarchical' => 'Tipe dimensi ini tidak hierarkis, sehingga nilainya tidak dapat memiliki induk.',
    'parent_not_found' => 'Induk tidak ada di tipe dimensi ini.',
    'parent_inactive' => 'Nilai induk nonaktif.',
    'parent_cycle' => 'Nilai tidak dapat dipindahkan ke bawah dirinya sendiri atau turunannya.',
    'hierarchy_in_use' => 'Sebagian nilai tipe dimensi ini memiliki induk, sehingga tipe harus tetap hierarkis.',
    'validity_order' => 'Akhir masa berlaku tidak boleh sebelum awalnya.',
    'type_unknown' => 'Tipe dimensi :key tidak ada di perusahaan ini.',
    'type_inactive_for' => 'Dimensi :type nonaktif.',
    'type_repeated' => 'Hanya satu nilai dimensi :type yang diperbolehkan.',
    'value_unknown' => 'Nilai tidak ada di dimensi :type.',
    'value_unknown_id' => 'Nilai dimensi tidak ada di perusahaan ini.',
    'value_inactive' => 'Nilai :code pada dimensi :type nonaktif.',
    'value_not_valid_on' => 'Nilai :code pada dimensi :type tidak berlaku pada :date.',
    'value_not_leaf' => 'Dimensi :type hanya menerima nilai daun, dan :code memiliki turunan.',
    'value_in_use' => 'Nilai ini memiliki turunan atau sudah pernah dipakai, sehingga hanya dapat dinonaktifkan.',

];
