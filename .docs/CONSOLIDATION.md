# Consolidation — Konsolidasi & Pelaporan Grup (`Post`)

> Modul `Consolidation` (prefix `CO`), Post-MVP. Bergantung pada `Reporting` dan `MasterData`. Dokumen ini menyiapkan desain; konsolidasi tidak masuk MVP dan tidak mengubah pembukuan entitas.

## Tujuan & batas

Menghasilkan laporan keuangan grup dari paket laporan beberapa perusahaan. Modul ini memiliki grup, perimeter, pemetaan akun grup, translasi, eliminasi, penyesuaian konsolidasi, dan snapshot grup. Ia tidak memiliki jurnal, COA, periode, dimensi, atau subledger entitas. Penyesuaian konsolidasi hanya ada di lapisan grup dan tidak pernah diposting ke buku entitas.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `Group` | Nama, entitas induk, mata uang penyajian |
| `Perimeter` | `company_id` anggota, dasar dan hasil penilaian pengendalian yang disetujui, tanggal efektif |
| `GroupAccountMapping` | Akun atau baris laporan entitas → bagan akun grup; berversi |
| `TranslationPolicy`, `GroupRate` | Kebijakan translasi, jenis kurs, kurs, sumber, versi |
| `ConsolidationRun` | Grup, periode, tanggal batas, entitas efektif, versi paket sumber dan pemetaan, kurs, kebijakan, pembuat, status, snapshot |
| `ConsolidationAdjustment` | Eliminasi atau penyesuaian: sumber, alasan, persetujuan, nilai |

## Kebutuhan

- **CO-FR-01 Akses.** Laporan grup memerlukan grant eksplisit pada grup (IA-FR-07). Drill-down ke data entitas tetap memerlukan akses ke workspace entitas tersebut. Setiap run diaudit.
- **CO-FR-02 Perimeter.** Menyimpan `company_id`, bukan workspace. Penilaian pengendalian memerlukan pertimbangan dan persetujuan akuntan [R9]; jangan menyimpulkannya dari membership atau persentase tanpa proses persetujuan. Satu entitas hanya masuk sekali per run, kecuali struktur bertingkat dikonfigurasi dan diuji secara eksplisit.
- **CO-FR-03 Paket sumber.** Setiap entitas menyediakan `ReportPackage`: periode dan tanggal batas, status tutup buku, mata uang fungsional, saldo per akun/baris, cakupan histori (RP-FR-13), referensi sumber, dan versi pemetaan. Cakupan parsial ditandai; kebijakan grup menentukan apakah run hanya boleh `draft` atau ditolak. Data yang tidak tersedia tidak pernah dianggap nol.
- **CO-FR-04 Tahap A — roll-up manajemen.** Agregasi ke bagan akun grup, diberi label "roll-up manajemen" bila eliminasi, translasi, atau persyaratan formal belum diterapkan. Akun tanpa pemetaan tampil sebagai pengecualian. Laporan grup per dimensi atau proyeksi grup memerlukan pemetaan terpisah; jangan menyatukan nilai dimensi hanya karena namanya sama.
- **CO-FR-05 Tahap B — konsolidasi formal.** Perimeter sesuai kerangka yang dipilih, eliminasi saldo dan transaksi intragrup, translasi, serta penyesuaian dan validasi terkait. Hasil tidak boleh diberi label laporan konsolidasian formal bila keadaan grup memerlukan fitur yang belum didukung.
- **CO-FR-06 Translasi.** Mata uang fungsional yang berbeda tidak pernah dijumlahkan langsung. Kurs, tanggal, jenis kurs, sumber, kebijakan, dan versinya disimpan agar hasil dapat direproduksi [R6].
- **CO-FR-07 Eliminasi.** Hanya untuk item intragrup yang membawa `counterparty_company_id` dan cocok menurut entitas lawan, periode, mata uang, nilai, serta toleransi kebijakan. Selisih pasangan tampil sebagai pengecualian dan tidak pernah dihapus otomatis.
- **CO-FR-08 Penyesuaian.** Setiap eliminasi dan penyesuaian memiliki sumber, alasan, persetujuan, dan jejak audit; nilainya hanya memengaruhi snapshot grup.
- **CO-FR-09 Run berversi.** Paket dalam satu run mewakili periode dan tanggal batas yang selaras; perbedaan periode memerlukan kebijakan dan penyesuaian cut-off yang terdokumentasi. Perubahan anggota, pemetaan, kurs, atau sumber membuat run baru; run lama tetap terbaca. Setiap baris grup dapat ditelusuri ke akun/baris entitas dan penyesuaiannya.
- **CO-FR-10 Keluaran.** Neraca saldo grup, neraca, laba rugi, arus kas, laporan eliminasi/penyesuaian, rekonsiliasi per entitas, dan ekspor.

## Di luar cakupan awal

Struktur kepemilikan kompleks, perubahan pengendalian di tengah periode, kombinasi bisnis/goodwill, entitas asosiasi dan ventura bersama, kepentingan nonpengendali, eliminasi laba belum terealisasi, ekonomi hiperinflasi, laporan grup per dimensi, anggaran grup, dan paket pengungkapan wajib. Semuanya ditetapkan bersama akuntan sebelum menjadi requirement, dan harus tersedia sebelum hasil dinyatakan sebagai laporan konsolidasian formal yang patuh.

## Kriteria penerimaan

- **CO-AC-01** User yang dapat membuka beberapa entitas tetapi tidak memiliki grant grup tidak dapat menjalankan konsolidasi.
- **CO-AC-02** Roll-up menampilkan setiap entitas efektif tepat sekali dan tidak menghilangkan akun tanpa pemetaan.
- **CO-AC-03** Konsolidasi dengan mata uang fungsional berbeda menolak penjumlahan langsung dan meminta kebijakan serta kurs yang lengkap.
- **CO-AC-04** Eliminasi atau penyesuaian konsolidasi tidak muncul di buku besar entitas.
- **CO-AC-05** Run baru setelah perubahan sumber atau pemetaan mempertahankan run lama, lengkap dengan versi dan jejak audit.
