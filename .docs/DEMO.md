# Data Demo

> Seeder yang mengisi satu perusahaan contoh untuk presentasi kepada calon pengguna. Bukan bagian dari produk dan bukan data uji otomatis; test memakai data buatannya sendiri.

## Menjalankan

```
php artisan db:seed --class=DemoSeeder
```

Seeder hanya **menambah** data dan tidak pernah menghapus atau mengubah yang sudah ada, jadi aman dijalankan di samping data lain. Bila `direktur@demo.test` sudah ada, seeder berhenti tanpa mengubah apa pun. Untuk mengulang dari awal, pakai database kosong, misalnya `DB_DATABASE=accounting_demo php artisan migrate --force` lalu seeder yang sama dengan `DB_DATABASE` itu. Data keuangan tidak boleh dihapus (X-FR-05), jadi tidak ada perintah "reset" di dalam seeder.

Lama jalan sekitar 4 sampai 6 menit untuk pembukuan sampai hari ini (sekitar 4.000 panggilan API). `DEMO_TODAY=2025-06-30` memendekkan pembukuan sampai tanggal itu untuk uji coba.

Perlu `php artisan passport:keys` bila kunci belum ada (klien integrasi).

## Cara kerjanya

Seeder memanggil API aplikasi sendiri di dalam proses, sebagai user yang sesuai (`database/seeders/Demo/DemoApi.php`). Setiap entri melewati validasi, persetujuan, penomoran, posting, dan audit yang sama dengan layar. Tidak ada tulis langsung ke tabel selain pembuatan user dan keanggotaan, yang di aplikasi dilakukan lewat undangan e-mail. Bila API menolak sesuatu, seeder berhenti dan menyebut alasannya.

Angkanya berasal dari seed acak tetap, jadi tiap jalan menghasilkan cerita yang sama.

## Perusahaan

PT Sinar Nusantara Perkasa, distributor alat kesehatan dan perlengkapan kantor. Mata uang rupiah saja. Buku dimulai 1 Januari 2025 dari saldo konversi sistem lama (kas, bank, persediaan, aset tetap, pinjaman, modal) beserta piutang dan utang awal, lalu berjalan sampai hari ini.

| Peran | E-mail | Kata sandi |
| --- | --- | --- |
| Direktur: pemilik, pengaturan, penyetuju tahap akhir | `direktur@demo.test` | `password` |
| Akuntan: penutupan, rekonsiliasi, anggaran, laporan | `akuntan@demo.test` | `password` |
| Admin keuangan: mencatat dan mengajukan | `admin.keuangan@demo.test` | `password` |
| Manajer keuangan: penyetuju | `manajer.keuangan@demo.test` | `password` |
| Auditor: baca saja | `auditor@demo.test` | `password` |
| Kepala cabang Surabaya: hanya melihat Surabaya | `kepala.surabaya@demo.test` | `password` |

## Jalur presentasi

| Fitur | Di mana melihatnya |
| --- | --- |
| Penyiapan: template COA, kalender, aktivasi | Beranda, Akun, Periode |
| Atribut akun, peran akun, kebijakan buku besar | Akun, Pengaturan buku besar |
| Dimensi bertingkat (cabang) dan departemen | Data induk → Dimensi |
| Jenis transaksi dari template dan rancangan sendiri (PPN, dimensi), penomoran | Transaksi → Jenis transaksi |
| Kebijakan persetujuan: pemisahan tugas, dua tahap untuk nilai besar | Transaksi → Kebijakan persetujuan |
| Entri menunggu persetujuan, ditolak, dibatalkan, draf, pembalikan, balik otomatis, lampiran | Kotak persetujuan, Entri (login sebagai manajer keuangan atau direktur) |
| Jurnal berulang (penyusutan, amortisasi sewa) | Jurnal berulang |
| Pihak, profil posting, referensi eksternal | Subledger → Pihak, Profil posting |
| Saldo piutang dan utang per pihak | Subledger → Pihak (kolom Piutang dan Utang) |
| Dokumen, open item, alokasi, pembayaran sebagian, uang muka | Dokumen, Open item, Alokasi |
| Umur piutang dan utang dengan bucket sendiri | Laporan → Umur piutang/utang |
| Kas, bank, transfer, rekening koran, rekonsiliasi bank | Kas dan bank, Transaksi kas, Rekening koran |
| Rekonsiliasi akun kontrol dan waiver saldo awal kas | Rekonsiliasi |
| Saldo konversi dan open item awal | Pembukuan → Saldo konversi |
| Tutup periode, periode tutup sementara, tutup tahun buku 2025 | Periode, Tutup tahun |
| Neraca saldo, buku besar, neraca, laba rugi, arus kas langsung dan tidak langsung, perubahan ekuitas | Laporan |
| Laporan terhadap anggaran, anggaran 2025 dan 2026, versi draf | Laporan, Anggaran |
| Snapshot laporan otomatis saat periode ditutup | Laporan → Snapshot |
| Cakupan data per cabang (login sebagai kepala cabang Surabaya) | Semua laporan dan daftar |
| Klien integrasi dengan cakupan Surabaya dan peristiwa keuangan | Akses → Klien integrasi, Kotak persetujuan |

## Yang tidak ada

- Konsolidasi grup dan akun grup: butuh lebih dari satu perusahaan.
- Mata uang asing, kurs, revaluasi, dan selisih kurs: perusahaan demo hanya memakai rupiah.
- 2FA dan passkey: tiap user perlu memasangnya sendiri; seeder tidak membuat rahasianya.
- Webhook: butuh antrean dan penjadwal yang berjalan.
