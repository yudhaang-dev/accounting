# ADR-0009 — Engine Definisi Laporan

Status: Diterima (2026-10-01)

## Konteks

Penyajian laporan tidak boleh di-hardcode (P-01). PSAK 201 menuntut OCI, laporan perubahan ekuitas, dan angka pembanding [R2]. IFRS 18 mengubah struktur laba rugi mulai 2027 [R17], dan perusahaan dapat memakai SAK, SAK EP, atau SAK EMKM.

## Keputusan

- Laporan keuangan disusun dari `ReportDefinition` berversi: baris (kumpulan akun, subtotal bertanda, klasifikasi mutasi, nilai terhitung) dan kolom (periode, YTD, pembanding, rencana, selisih).
- Kumpulan akun dipilih berdasarkan ID, akun induk, atau kelas akun, tidak pernah rentang kode.
- `MovementClassification` (kumpulan akun × key Jenis Transaksi → baris) melayani arus kas metode langsung dan perubahan ekuitas dengan mekanisme yang sama.
- Neraca saldo dan buku besar tetap laporan bawaan. Laporan terbit disimpan sebagai snapshot.

## Konsekuensi

Format baru cukup berupa data. Engine lebih kompleks daripada laporan tetap, tetapi menggantikan beberapa kelas laporan sekaligus. Template disediakan sebagai saran.

## Alternatif ditolak

Empat kelas laporan tetap dengan pemetaan akun-ke-baris (struktur masih di kode). Bahasa formula bebas (MVP melarang skrip buatan user).
