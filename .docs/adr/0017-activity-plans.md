# ADR-0017 — Rencana Kegiatan: Periode Mandiri dan Ruang Lingkup Dimensi

Status: Diterima (2026-10-30)

## Konteks

Modul `Planning` mengikat rencana ke tahun buku: `Plan` punya tahun buku, baris dihitung per periode GL, dan baris di luar tahun buku ditolak (PL-AC-02). Bentuknya anggaran tahunan.

Maksud fitur ini lain: perusahaan menyusun estimasi untuk satu **kegiatan** (keputusan, strategi, atau taktik bisnis) pada periode tertentu, lalu membandingkannya dengan pembukuan yang sebenarnya sebagai **tolok ukur evaluasi**: apakah realisasinya berjalan baik, atau rencananya yang memang buruk. Rencana tidak membatasi pencatatan dan tidak menjadi jurnal; ia hanya bahan ukur kebijakan operasional atau strategis.

Dua kebutuhan yang tidak terpenuhi sekarang: periode kegiatan yang berdiri sendiri (tidak harus satu tahun buku, boleh lintas tahun), dan kaitan eksplisit antara rencana dan bagian pembukuan yang diukurnya.

## Keputusan

1. **Rencana punya judul, tujuan, periode sendiri, dan ruang lingkup opsional.** Judul kegiatan, tujuan atau hipotesis yang dievaluasi (teks), tanggal mulai dan selesai, dan satu nilai dimensi sebagai ruang lingkup. Keterikatan ke tahun buku dihapus; baris tetap per periode GL (PL-FR-02) dan dapat melintasi tahun buku, selama kalender fiskal untuk periode itu ada. PL-AC-02 berubah: baris harus berada di dalam periode rencana dan pada periode GL yang ada.
2. **Ruang lingkup dipilih saat membuat rencana** (tipe dimensi, lalu nilainya) dan tidak berubah sesudah versi pertama diterbitkan, karena mengubahnya mengubah arti seluruh riwayat perbandingan. Tidak ada pintu masuk kedua di layar dimensi. Rencana tanpa ruang lingkup berarti seluruh perusahaan, sama dengan anggaran umum sekarang.
3. **Realisasi dibaca otomatis dari buku besar**, tanpa input: dalam periode rencana, difilter pada dimensi ruang lingkup beserta turunannya (MD-FR-05), per akun dan per periode GL. Operator hanya memberi tag dimensi saat mencatat. Tanpa ruang lingkup, realisasinya seluruh perusahaan pada periode itu. Tidak ada tulisan ke GL dan tidak ada pembatasan entri.
4. **Layar evaluasi menampilkan estimasi awal, revisi, dan aktual berdampingan.** Estimasi awal adalah versi terbit pertama; revisi adalah versi terbit terakhir atau yang dipilih pembaca; keduanya memakai riwayat versi yang tidak pernah ditimpa (PL-FR-01). Untuk tiap akun dan tiap periode: selisih dan persen terhadap masing-masing versi, serta selisih bertanda kumulatif agar terlihat apakah perkiraan condong ke satu arah. Penilaian menguntungkan atau merugikan memakai aturan selisih yang ada (RP-FR-12). Sistem menyajikan angka; menafsirkan apakah masalahnya eksekusi atau mutu rencana adalah tugas pembaca.
5. **Migrasi.** Rencana yang ada mendapat periode sama dengan tahun buku asalnya dan tanpa ruang lingkup, sehingga perilakunya tidak berubah. Versi pembanding default per tahun buku (`PlanningPolicy`) tetap melayani laporan umum; layar evaluasi memilih versi pembandingnya sendiri.

### Yang perlu dibuktikan saat implementasi, bukan diasumsikan

- Bahwa kolom `plan` pada definisi laporan dan `PlanReader` bekerja pada rentang yang melintasi tahun buku (RP-FR-13 menandai kolom di luar kalender fiskal dengan `available: false`).
- Bahwa filter dimensi beserta turunannya pada realisasi memberi angka yang sama dengan laporan laba rugi yang difilter dimensi yang sama.
- Bahwa nilai dimensi dengan masa berlaku (MD-FR-03) tidak menolak entri pada periode kegiatan yang bergeser dari masa berlakunya.

## Konsekuensi

Pemilik bisnis dapat menilai keputusan dengan dua pertanyaan terpisah, kinerja terhadap rencana dan mutu rencana itu sendiri, tanpa menambah entitas baru: kegiatan hanyalah rencana yang memakai dimensi yang sudah ada, sesuai P-01. Anggaran tahunan umum tetap berfungsi. Perubahan menyentuh `Planning` (header rencana, migrasi, validasi baris), `Reporting` (pembacaan lintas tahun dan perbandingan versi), SPA (form rencana, layar evaluasi, editor baris per periode rencana), OpenAPI, dan panduan. Modul lain tidak terpengaruh.

## Alternatif ditolak

- **Tab "Rencana" di layar dimensi sebagai pintu masuk:** dua jalan membuat rencana tanpa manfaat; memilih dimensi saat membuat rencana lebih sederhana.
- **Entitas "Kegiatan" atau "Proyek" baru:** menduplikasi dimensi dan menanam konsep bisnis di kode.
- **Anggaran sebagai batas pencatatan (peringatan atau persetujuan tambahan):** di luar tujuan, yaitu tolok ukur evaluasi; dan membuat entri bergantung pada rencana.
- **Satu rencana per tahun buku per dimensi:** memaksa kegiatan pendek atau lintas tahun menjadi anggaran setahun penuh, tepat masalah yang ingin dihindari.
