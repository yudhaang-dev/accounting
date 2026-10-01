# Reporting — Definisi Laporan, Laporan Keuangan, Proyeksi vs Realisasi

> Modul `Reporting` (prefix `RP`). Bergantung pada `GeneralLedger`, `Planning`, dan `MasterData`; hanya membaca.

## Tujuan & batas

Menghasilkan laporan keuangan dan analisis dari jurnal posted dan rencana. Struktur laporan adalah data berversi, sehingga format dapat mengikuti kebijakan perusahaan dan perubahan standar tanpa perubahan kode (mis. IFRS 18 [R17]). Modul ini tidak menulis jurnal atau rencana; koreksi dilakukan di pemilik data. Aging dan rekonsiliasi milik `Subledger`.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `ReportDefinition` | Baris, kolom, aturan tanda, filter default, versi; template sebagai saran |
| `MovementClassification` | Aturan (kumpulan akun × key Jenis Transaksi) → baris laporan; versi |
| `AccountRoleMapping` | Peran `cash_equivalents` (satu atau lebih akun) |
| `ReportSnapshot` | Hasil laporan terbit: parameter, versi definisi dan pemetaan, batas data, nilai |

## Interface publik

- `ReportPackage` — hasil laporan entitas beserta versi dan cakupan histori, untuk `Consolidation` (`Post`).

## Kebutuhan

### Engine definisi

- **RP-FR-01 Definisi laporan.** Laporan disusun dari baris (judul, kumpulan akun, subtotal berupa penjumlahan bertanda atas baris lain, klasifikasi mutasi, atau nilai terhitung seperti `current_year_earnings`) dan kolom (periode, YTD, per tanggal, pembanding, versi rencana, selisih). Setiap baris menetapkan dasar nilai: saldo per tanggal, mutasi rentang, saldo awal, atau saldo akhir. Definisi berversi; template SAK, SAK EP, dan SAK EMKM hanya saran berupa data.
- **RP-FR-02 Kumpulan akun.** Dipilih berdasarkan ID akun, akun induk (beserta turunannya), atau kelas akun; tidak pernah berdasarkan rentang kode. Akun posting yang tidak masuk baris mana pun ditampilkan di baris pengecualian sehingga total tetap rekonsiliasi.
- **RP-FR-03 Klasifikasi mutasi.** Aturan memetakan mutasi menurut kumpulan akun dan key Jenis Transaksi ke baris laporan; dipakai untuk arus kas metode langsung dan perubahan ekuitas. Mutasi tanpa aturan masuk baris "belum diklasifikasikan" dan tetap dihitung.
- **RP-FR-04 Filter dan agregasi.** Laporan menerima rentang tanggal atau `as_of`, akun, nilai dimensi apa pun (filter nilai induk mencakup turunannya), key Jenis Transaksi, sumber, versi rencana, dan basis (akrual atau kas untuk proyeksi vs realisasi). Agregasi perusahaan mencakup semua dimensi; filter kosong tidak mengecualikan baris tanpa dimensi. Cakupan data user diterapkan (IA-FR-03). Nilai disajikan dalam mata uang fungsional.

### Laporan

- **RP-FR-05 Neraca saldo.** Saldo awal, mutasi debit, mutasi kredit, dan saldo akhir per akun; saldo awal berasal dari ledger sebelum rentang; total debit sama dengan kredit. Mendukung drill-down ke jurnal, penanda batch saldo konversi dan histori, serta mode kertas kerja: kolom sebelum penyesuaian, penyesuaian (key Jenis Transaksi terpilih), dan sesudah penyesuaian.
- **RP-FR-06 Buku besar.** Rincian baris jurnal per akun dengan saldo berjalan, nilai mata uang transaksi, dan tautan ke entri serta referensi sumber.
- **RP-FR-07 Laba rugi.** Per periode dan YTD, dengan pembanding periode atau rencana, termasuk bagian OCI bila didefinisikan. Laporan periode hanya memuat mutasi rentangnya dan mengecualikan saldo YTD dari saldo konversi; YTD menjumlahkan sejak awal tahun buku termasuk saldo tersebut. Laporan untuk tahun yang sudah ditutup mengecualikan `system.closing`.
- **RP-FR-08 Neraca.** Saldo kumulatif per tanggal dengan pembanding tanggal lain. `current_year_earnings` dihitung dari akun pendapatan/beban tahun buku berjalan dan disajikan sekali; setelah jurnal penutup diposting, hasilnya tampil di saldo laba tanpa dihitung ulang. Total aset harus sama dengan liabilitas + ekuitas; selisih ditampilkan sebagai kesalahan data, tidak disembunyikan.
- **RP-FR-09 Arus kas.** Metode langsung dan tidak langsung lewat definisi; kas dan setara kas memakai peran `cash_equivalents` [R3]. Metode langsung mengklasifikasikan baris lawan pada jurnal yang menyentuh kas, sehingga satu pembayaran dapat memuat beberapa klasifikasi dan transfer antarakun kas bernilai nol. Metode tidak langsung menyesuaikan laba dengan mutasi akun nonkas dan akun neraca. Saldo awal + kenaikan bersih = saldo akhir akun `cash_equivalents`; pemetaan yang belum lengkap tampil sebagai validasi. Jurnal penutup tidak memengaruhi arus kas.
- **RP-FR-10 Perubahan ekuitas.** Matriks komponen ekuitas (kumpulan akun) × jenis mutasi (laba tahun berjalan, OCI, distribusi, setoran modal, koreksi periode lalu [R5]) yang diambil dari klasifikasi mutasi. Saldo awal + mutasi = saldo akhir per komponen dan cocok dengan neraca [R2].
- **RP-FR-11 Proyeksi vs realisasi.** Biaya dan pendapatan menurut akun, periode, dan dimensi. Realisasi default adalah nilai akrual dari jurnal posted. Metrik kas disajikan terpisah sebagai arus kas metode langsung pada periode yang sama; atribusi pembayaran ke akun biaya asal adalah `F2`.
- **RP-FR-12 Selisih.** Selisih = realisasi − rencana; persentase = selisih ÷ |rencana| × 100, atau `null` bila rencana nol. Arah menguntungkan mengikuti saldo normal akun: untuk akun bersaldo normal kredit, realisasi lebih tinggi menguntungkan; untuk akun bersaldo normal debit (beban, kontra-pendapatan), realisasi lebih rendah menguntungkan. Pada baris subtotal, arah mengikuti konfigurasi baris.
- **RP-FR-13 Cakupan histori.** Laporan menyatakan rentang data yang tersedia (`coverage_from`, `coverage_to`, `coverage_granularity`, `coverage_status`). Laporan bulanan sebelum `cutover_date` memerlukan ringkasan per periode atau transaksi; arus kas sebelum `cutover_date` hanya lengkap bila data terklasifikasi tersedia. Data yang tidak tersedia ditandai `partial`/`unavailable`, tidak pernah ditampilkan sebagai nol.
- **RP-FR-14 Snapshot.** Laporan yang diterbitkan, termasuk saat tutup periode atau tahun, disimpan sebagai snapshot dengan parameter, versi definisi dan pemetaan, serta batas data. Perhitungan ulang membuat snapshot baru (X-FR-03). Laporan lain dihitung langsung saat diminta.

## Kriteria penerimaan

- **RP-AC-01** Neraca saldo: total debit = kredit. Neraca memenuhi persamaan akuntansi; ketidakseimbangan menghasilkan status error yang terlihat.
- **RP-AC-02** Arus kas merekonsiliasi saldo awal, kenaikan bersih, dan saldo akhir kas/setara kas; mutasi tanpa klasifikasi ditandai dan tetap masuk total.
- **RP-AC-03** Baris dengan dan tanpa proyek sama-sama masuk total perusahaan; filter proyek hanya mengembalikan proyek terpilih; filter departemen induk mencakup turunannya.
- **RP-AC-04** Rencana biaya 100 dan realisasi 80 menghasilkan selisih −20, −20%, menguntungkan; pendapatan dengan nilai sama tidak menguntungkan; kontra-pendapatan 80 terhadap rencana 100 menguntungkan; rencana 0 menghasilkan persentase `null`.
- **RP-AC-05** Cutover di tengah tahun: laba rugi bulan berjalan tidak memuat saldo YTD konversi, sedangkan laba rugi YTD memuatnya.
- **RP-AC-06** Setelah tutup tahun, laba rugi tahun itu tetap menunjukkan hasil sebelum penutupan; neraca menyajikan hasilnya di saldo laba tanpa `current_year_earnings` ganda.
- **RP-AC-07** Memindahkan akun ke baris lain atau menambah baris tidak memerlukan perubahan kode; akun yang belum dipetakan muncul di baris pengecualian dan total tetap cocok.
- **RP-AC-08** Snapshot terbit tidak berubah ketika definisi atau pemetaan diubah; perhitungan ulang menghasilkan snapshot baru dengan versi tercatat. `current_year_earnings` tidak pernah tampil sebagai jurnal.
- **RP-AC-09** Laporan perubahan ekuitas: saldo awal + mutasi = saldo akhir per komponen dan sama dengan ekuitas di neraca.
- **RP-AC-10** Laporan sebelum `cutover_date` tanpa data histori ditandai `unavailable` atau `partial`, bukan nol.
- **RP-AC-11** Kertas kerja neraca saldo: sebelum penyesuaian + penyesuaian = sesudah penyesuaian untuk setiap akun.
