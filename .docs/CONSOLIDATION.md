# Kebutuhan Bisnis Konsolidasi & Pelaporan Grup — Post-MVP

> **Jenis dokumen:** kebutuhan bisnis dan aturan akuntansi Post-MVP. Setiap workspace tetap memiliki satu entitas pembukuan dengan buku besar mandiri. Dokumen ini menyiapkan konsolidasi masa depan; tidak memasukkan konsolidasi ke cakupan MVP atau mengubah pembukuan entitas. Batas modul dan arah dependensi dirinci di [DOMAIN-DEPENDENCIES.md](DOMAIN-DEPENDENCIES.md).

## Tujuan dan batas domain

Consolidation & Group Reporting menghasilkan tampilan keuangan kelompok entitas berdasarkan data laporan dari beberapa workspace. Domain ini memiliki konfigurasi grup, perimeter konsolidasi, pemetaan bagan akun grup, translasi/eliminasi, penyesuaian konsolidasi, snapshot, dan laporan grup.

Domain ini **tidak** memiliki jurnal sumber entitas, bagan akun entitas, periode buku entitas, departemen, proyek, atau transaksi subledger. Perubahan pembukuan entitas tetap dilakukan melalui General Ledger entitas itu sendiri. Penyesuaian konsolidasi tersimpan di lapisan konsolidasi dan tidak diposting ke buku entitas.

## Keanggotaan user dan keanggotaan grup

- `company_id` adalah ID stabil entitas pembukuan; pada MVP setiap workspace memiliki tepat satu `company_id`. Perimeter konsolidasi menyimpan `company_id`, bukan workspace ID.
- Seorang user dapat memiliki akses ke beberapa workspace/entitas dengan role berbeda.
- Akses user ke beberapa entitas **tidak otomatis** membentuk grup konsolidasi.
- Perimeter grup menyimpan entitas induk dan entitas yang disertakan, dasar/hasil penilaian pengendalian yang disetujui, serta tanggal efektif mulai/akhir. Penilaian pengendalian memerlukan pertimbangan akuntansi; jangan menyimpulkannya hanya dari keanggotaan workspace atau persentase yang dimasukkan tanpa proses persetujuan. [R1]
- User memerlukan role eksplisit pada grup untuk membuka laporan konsolidasi. Drill-down sampai data entitas tetap memerlukan hak akses pada workspace entitas tersebut; layanan konsolidasi hanya boleh membaca paket sumber yang disetujui dan setiap run diaudit.

## Informasi dan keluaran konsolidasi

Setiap entitas dalam laporan grup harus menyediakan periode dan tanggal batas, status tutup buku, mata uang fungsional, saldo per akun/baris laporan, cakupan historis per laporan (`coverage_from`, `coverage_to`, `coverage_granularity`, `coverage_status`), referensi sumber, dan versi pemetaan akun/peran. Granularitas serta status cakupan mengikuti [PRODUCT.md](PRODUCT.md). Riwayat parsial karena cutover harus tampak dan tidak boleh dianggap lengkap. Untuk eliminasi intragrup, data harus menyertakan `counterparty_entity_id` (ID entitas lawan) dan referensi sumber/dokumen pasangan; field ini belum wajib pada MVP.

Keluaran bisnis meliputi neraca saldo grup, neraca, laba rugi, arus kas, laporan eliminasi/penyesuaian, rekonsiliasi per entitas, dan ekspor. Hasil konsolidasi memakai data dan kebijakan yang telah disetujui serta disimpan sebagai snapshot berversi agar dapat ditelusuri.

## Tahapan kemampuan

### Tahap A — roll-up manajemen

Agregasikan laporan entitas ke bagan akun/pemetaan grup yang disepakati. Hasil harus diberi label jelas sebagai roll-up manajemen apabila eliminasi intragrup, translasi, atau persyaratan laporan formal belum diterapkan. Akun yang belum dipetakan ditampilkan sebagai pengecualian dan tidak boleh hilang diam-diam. Baseline hanya menyajikan laporan pada tingkat entitas/grup; laporan gabungan menurut departemen/proyek dan proyeksi vs realisasi grup memerlukan pemetaan dimensi/plan terpisah. Jangan menyatukan departemen atau proyek hanya karena namanya sama.

### Tahap B — konsolidasi formal

Tambahkan penentuan perimeter sesuai kerangka akuntansi yang dipilih, eliminasi saldo dan transaksi intragrup, translasi mata uang bila diperlukan, serta penyesuaian dan validasi relevan. IFRS 10 mendasarkan konsolidasi pada pengendalian; IAS 21 membahas translasi ketika mata uang fungsional entitas berbeda dari mata uang penyajian grup. Perlakuan akhir harus ditinjau akuntan menurut SAK yang berlaku untuk grup. Jika keadaan grup memerlukan fitur yang belum didukung, hasil tidak boleh diberi label patuh sebagai laporan konsolidasian formal. [R1][R2]

## Invarian konsolidasi

- Setiap hasil menyimpan grup, periode, tanggal batas, daftar entitas efektif, mata uang penyajian, versi paket sumber, versi pemetaan peran/penyajian akun entitas dan pemetaan grup, versi kurs, kebijakan, dan pembuat run.
- Semua paket sumber untuk satu run harus mewakili periode dan tanggal batas yang selaras. Perbedaan periode entitas memerlukan kebijakan dan penyesuaian cut-off yang terdokumentasi; jangan mencampur periode berbeda tanpa penanda.
- Entitas hanya masuk satu kali pada satu perimeter/run kecuali ada struktur grup bertingkat yang dikonfigurasi dan diuji secara eksplisit.
- Akun sumber wajib dipetakan ke bagan akun grup sebelum hasil ditetapkan final. Akun tanpa pemetaan dan periode sumber yang belum ditutup ditandai; kebijakan grup menentukan apakah run hanya dapat berstatus draf atau ditolak.
- Mata uang fungsional entitas tidak boleh dijumlahkan langsung jika berbeda. Kurs, tanggal, jenis kurs, sumber, kebijakan translasi, dan versi kurs harus disimpan agar hasil dapat direproduksi.
- Transaksi intragrup harus membawa ID entitas lawan (`counterparty_entity_id`) dan referensi dokumen pasangan bila tersedia. Data tanpa pihak lawan tidak boleh dieliminasi otomatis.
- Paket sumber dengan cakupan parsial ditandai sebagai pengecualian; kebijakan grup menentukan apakah run hanya dapat berstatus draf atau ditolak. Data yang tidak tersedia tidak boleh diperlakukan sebagai saldo nol.
- Eliminasi hanya berlaku pada item intragrup yang teridentifikasi dan cocok menurut entitas lawan, periode, mata uang, nilai, dan kebijakan toleransi. Selisih pasangan ditampilkan sebagai pengecualian; sistem tidak boleh menghapus selisih otomatis.
- Jurnal eliminasi dan penyesuaian memiliki sumber, alasan, persetujuan, serta jejak audit. Nilainya memengaruhi snapshot konsolidasi saja, tidak mengubah jurnal sumber.
- Perubahan anggota grup, pemetaan, kurs, atau sumber membuat run baru. Run lama tetap dapat dibaca dan ditelusuri.
- Setiap laporan grup dapat ditelusuri dari baris grup ke akun/baris laporan entitas dan penyesuaian konsolidasinya.

## Bukan cakupan awal

Konsolidasi belum termasuk MVP. Hubungan kepemilikan dan pengendalian yang kompleks, perubahan kontrol di tengah periode, kombinasi bisnis/goodwill, entitas asosiasi/ventura bersama, kepentingan nonpengendali, eliminasi laba belum terealisasi, translasi ekonomi hiperinflasi, laporan grup menurut departemen/proyek, anggaran grup, dan paket pengungkapan wajib ditentukan terpisah bersama akuntan sebelum menjadi requirement. Jika salah satu diperlukan, kemampuan terkait harus tersedia sebelum hasil dinyatakan sebagai laporan konsolidasian formal yang patuh.

## Kriteria penerimaan saat domain dibangun

- User yang dapat membuka beberapa entitas tetapi tidak mempunyai akses/perimeter grup yang disetujui tidak dapat menjalankan laporan konsolidasi.
- Roll-up menampilkan semua entitas yang efektif pada periode dan tidak menggandakan entitas atau menghilangkan akun tanpa pemetaan.
- Konsolidasi mata uang campuran menolak penjumlahan langsung dan meminta kebijakan/kurs yang lengkap.
- Eliminasi atau penyesuaian konsolidasi tidak muncul dalam buku besar entitas.
- Run baru setelah perubahan sumber/pemetaan mempertahankan run lama dan mencatat versi serta jejak audit.

## Referensi

- [R1] IFRS Foundation, [IFRS 10 — Consolidated Financial Statements](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-10-consolidated-financial-statements/): dasar pengendalian dan penyusunan laporan konsolidasian.
- [R2] IFRS Foundation, [IAS 21 — The Effects of Changes in Foreign Exchange Rates](https://www.ifrs.org/issued-standards/list-of-standards/ias-21-the-effects-of-changes-in-foreign-exchange-rates/): translasi mata uang fungsional ke mata uang penyajian.
