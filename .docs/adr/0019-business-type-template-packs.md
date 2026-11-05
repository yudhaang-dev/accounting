# ADR-0019 — Paket Template per Jenis Usaha

Status: Diusulkan (2026-11-05)

## Konteks

Template sekarang berupa berkas data di tiap modul: bagan akun `general-id` (GL), definisi laporan `general-id` (RP), jenis transaksi `general` dan `daily-operations` (TE), dan peran pihak (SL). Tiap potong ditinjau dan dikonfirmasi sendiri-sendiri di layarnya masing-masing (X-AC-03), dan urutannya harus benar: peran pihak sebelum jenis transaksi yang memakainya (ADR-0015), akun sebelum laporan yang menamai kodenya, profil posting sebelum jenis transaksi berrute. Semuanya berbentuk umum; belum ada isi yang berbeda untuk toko, jasa, kontraktor, pabrik kecil, atau kedai makan.

Akibatnya perusahaan baru, terutama usaha kecil tanpa akuntan, harus memahami sendiri bagian mana yang dipasang dan dalam urutan apa. Akuntan yang menyiapkan banyak perusahaan sejenis mengulang pekerjaan yang sama.

P-01 dan X-AC-03 tetap berlaku: template hanya saran, wajib ditinjau dan dikonfirmasi, dan semua hasilnya adalah data per perusahaan yang bisa diubah sesudahnya.

## Keputusan

1. **Paket template adalah manifes data**, bukan kode dan bukan data perusahaan. Satu berkas per paket di `app/Orchestration/Setup/packs/`, memuat kunci, nama, versi, dan daftar **komponen** menurut kunci dan versi templatenya: bagan akun, peran pihak, profil posting, jenis transaksi (satu atau lebih), definisi laporan, dan pemetaan peran akun (misalnya `cash_equivalents`). Modul tetap memiliki templatenya sendiri; paket hanya merujuk. Paket tidak menyalin isi komponen.
2. **Paket disusun di Orchestration**, karena menyentuh lebih dari satu modul (ADR-0002). Use case `ApplyTemplatePack` memanggil aksi impor tiap modul lewat `Contracts` yang ada atau ditambah; tidak ada modul yang mengimpor modul lain. Urutan pemasangan ditetapkan manifes dan diperiksa: akun → peran pihak → profil posting dan pemetaan peran akun → jenis transaksi → definisi laporan.
3. **Dua macam paket.** *Paket jenis usaha*: dagang atau ritel, jasa, kontraktor atau proyek, manufaktur ringan, dan makanan dan minuman. *Modul tambahan* yang dipasang terpisah, bisa belakangan, di atas bagan yang sudah ada: kasbon dan pinjaman, pajak, persediaan dan HPP, proyek. Modul tambahan hanya **menambah**: akun dengan kode yang sudah ada dilewati dan dilaporkan, tidak pernah ditimpa; kode yang sama dengan nama atau kelas berbeda ditampilkan sebagai konflik untuk diputuskan pengguna.
4. **Isi komponen yang berbeda per jenis usaha** hidup sebagai template modulnya masing-masing. Bagan akun varian menyatakan `extends: general-id` dan hanya memuat akun tambahan (persediaan dan HPP untuk dagang, pekerjaan dalam proses untuk kontraktor, bahan baku dan barang jadi untuk manufaktur), sehingga bagan dasar tidak digandakan dan tetap dijaga sinkron dengan [CHART-OF-ACCOUNTS.md](../CHART-OF-ACCOUNTS.md) oleh test yang sudah ada. Akun tambahan didokumentasikan di berkas yang sama dengan bagan dasar.
5. **Alur pemasangan satu pintu, tetap berkonfirmasi.** Pengguna memilih paket, melihat **ringkasan per komponen** (akun, jenis transaksi, laporan, peran pihak yang akan dibuat; yang sudah ada ditandai), mencentang komponen atau item opsional yang dipakai, lalu memberi satu konfirmasi. Pemasangan berjalan dalam satu transaksi database, semua atau tidak sama sekali, dan hasilnya menyebut apa yang dibuat dan apa yang dilewati. Tanpa konfirmasi tidak ada yang dibuat. Pemanggil harus memegang gabungan permission pengelolaan tiap komponen yang dipilih; kekurangan izin ditolak sebelum apa pun dibuat. Layar impor per modul tetap ada dan tidak berubah.
6. **Pilihan sederhana** untuk usaha kecil tanpa akuntan adalah profil paket (`simple`) yang memilih himpunan bagian: hanya akun inti (bukan yang `optional`), jenis transaksi harian, dan satu set laporan. Profil ini tidak mengubah kebijakan approval, periode, atau SoD; itu tetap keputusan perusahaan (P-01).
7. **Catatan pemasangan.** Pemasangan dicatat di audit dengan kunci dan versi paket serta tiap komponen. Tidak ada tabel baru: daftar paket yang tersedia dan komponen yang sudah ada di suatu perusahaan diturunkan dari kehadiran kunci templatenya. Versi paket yang lebih baru tidak mengubah perusahaan yang sudah memasang; pemasangan ulang adalah tindakan baru yang berkonfirmasi.
8. **Isi paket ditinjau akuntan sebelum dirilis** (proses, bukan kode), dengan rujukan standar di REFERENCES.md (SAK EMKM atau PSAK yang berlaku, PPN). Paket yang belum ditinjau ditandai `reviewed: false` pada manifes dan tidak tampil di daftar produksi.
9. **Batas.** Tanpa stok per item: persediaan dan HPP hanya akun dan jurnal penyesuaian. Pajak hanya akun dan jenis transaksi, bukan mesin pajak. Tidak ada pembaruan otomatis dari versi paket baru ke perusahaan yang sudah berjalan.

### Yang perlu dibuktikan saat implementasi, bukan diasumsikan

- Bahwa pemasangan satu transaksi benar-benar tidak meninggalkan setengah jadi ketika komponen kelima gagal, termasuk penomoran dan audit.
- Bahwa profil posting dan pemetaan peran akun dapat dibuat dari saran berisi kode akun (profil milik perusahaan, template tidak dapat menamainya; lihat B-09g), tanpa melanggar X-AC-03.
- Bahwa modul tambahan di atas bagan yang sudah diubah pengguna (akun diganti nama, dinonaktifkan) melaporkan konflik dengan benar dan tidak menimpa.
- Bahwa laporan paket dagang, jasa, dan kontraktor tidak punya baris `unmapped` pada bagan hasil pemasangannya.
- Bahwa tiap paket memasang bersih pada perusahaan kosong dan lulus aktivasi (MD-AC-05).

## Konsekuensi

Perusahaan baru dapat dimulai dari satu pilihan jenis usaha tanpa mengetahui urutan impor, dan akuntan dapat mengulang penyiapan yang konsisten. Kebutuhan baru berupa manifes dan template varian, tanpa skema database baru. Biayanya ada pada isi: tiap paket butuh kerja dan tinjauan akuntan, dan jumlah paket akan menambah beban pemeliharaan yang dijaga test sinkronisasi dan test pemasangan bersih. Layar impor per modul tetap menjadi jalur untuk yang ingin memilih sendiri.
