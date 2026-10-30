# ADR-0015 — Peran Pihak sebagai Data

Status: Diusulkan (2026-10-30)

## Konteks

SL-FR-01 menyebut peran pihak sebagai pelanggan atau pemasok, dan kodenya menyimpannya sebagai dua kotak centang (`is_customer`, `is_supplier`). Pencatat dokumen memakai kotak itu untuk menentukan akun kontrol yang boleh: akun piutang mensyaratkan pelanggan, akun utang mensyaratkan pemasok. Dua hal yang berbeda tercampur di sana: **jenis pihak** (siapa dia bagi perusahaan) dan **sisi buku besar** yang dipakai (piutang atau utang).

Akibatnya tampak saat uji ujung ke ujung ([TEMUAN](../manual-book/TEMUAN.md) 13, 19, 23): karyawan harus berstatus pelanggan agar dapat menerima kasbon, pemberi pinjaman harus berstatus pemasok, dan daftar "Pelanggan" di form entri memuat pemasok. P-01 melarang kebijakan yang berbeda antarperusahaan ditanam di kode, dan jenis pihak jelas berbeda antarbudaya usaha (karyawan, pemodal, pemegang saham, mitra proyek, dan seterusnya). Satu pihak juga dapat memegang beberapa peran sekaligus.

Studi kasus yang harus dapat dipenuhi:

1. Kasbon karyawan (dokumen `loan`, B-08).
2. Setoran modal pemegang saham yang berbentuk piutang.
3. Satu orang yang sekaligus karyawan dan pemegang saham, dengan saldo yang tidak bercampur.
4. Pemberi pinjaman sebagai peran tersendiri, bukan pemasok.

## Keputusan

1. **Peran pihak adalah data per perusahaan.** Setiap peran punya `key` stabil, label, dan status; pihak dapat memiliki banyak peran. Peran bawaan (Pelanggan, Pemasok, Karyawan, Pemberi pinjaman, Pemegang saham) adalah template yang diimpor dengan konfirmasi, seperti template lain (X-AC-03). Peran yang pernah dipakai hanya dapat dinonaktifkan. Di kode: `PartyRole`, berbeda dari `ControlRole` (peran akun kontrol) dan role IAM; di UI dan dokumen: "Peran pihak".
2. **Peran tidak menentukan sisi buku besar.** Syarat `isCustomer`/`isSupplier` di pencatat dokumen dan impor open item awal dihapus. Yang menentukan akun kontrol hanyalah pemetaan profil posting yang berlaku. Konsekuensinya, pihak berperan pemasok boleh menerima dokumen piutang (mis. pengembalian dana), sengaja.
3. **Field `party` pada versi Jenis Transaksi menerima `allowed_roles`** (daftar key peran; kosong berarti semua), sejajar dengan kumpulan akun yang diizinkan pada field `account` (TE-FR-04). Peran dicek ada dan aktif saat versi diterbitkan, dan pihak dicek memiliki salah satu peran itu saat entri disimpan atau peristiwa diterima (error pada field, TE-FR-08). Template bawaan memasang pembatas ini sehingga pembatasan yang dulu keras kini berupa konfigurasi. Daftar pilihan pihak di form disaring dengan aturan yang sama.
4. **Rute subledger boleh menamai profil posting sendiri** (`posting_profile_id`, opsional). Bila ada, akun kontrol diambil dari profil itu, bukan dari profil pihaknya; bila kosong, memakai profil pihak seperti sekarang. Divalidasi saat terbit (profil ada dan memetakan peran kontrol rute) dan saat entri (`MAPPING_REQUIRED` bila pemetaan hilang pada tanggal akuntansi). Inilah yang memisahkan saldo orang berperan ganda menurut sifat transaksinya, bukan menurut orangnya: kasbon selalu ke akun piutang karyawan, setoran modal selalu ke akun piutang pemegang saham. Rujukannya praktik ERP besar yang memakai akun rekonsiliasi alternatif per jenis transaksi untuk pihak yang sama.
5. **API.** `roles` (daftar key) menggantikan `is_customer` dan `is_supplier`, yang dihapus dari `/api/v1` tanpa masa transisi. Ini pengecualian atas API-TR-01 yang dicatat di sini karena sistem belum dikonsumsi siapa pun; setelah rilis pertama, API-TR-01 berlaku penuh. Data lama dimigrasi: `is_customer` menjadi peran `customer`, `is_supplier` menjadi peran `supplier`, dibuat otomatis untuk perusahaan yang sudah ada karena hanya menyalin data yang sudah ada.

### Pilihan akuntansi adalah konfigurasi

Klasifikasi dan penyajian (mis. piutang setoran modal sebagai pengurang ekuitas, pinjaman berbagi hasil) diatur akuntan lewat konfigurasi yang sudah ada dan tidak ditunggu. Satu hal adalah celah produk, bukan pilihan akuntan: kas tanpa pihak dan akun kontrol, dikerjakan sebagai B-10 (ADR-0016). Bila pembuktian di B-09h menemukan konfigurasi yang tidak dapat dinyatakan, itu cacat produk yang dikerjakan.

## Konsekuensi

Pencegahan salah pilih pindah dari kode ke konfigurasi Jenis Transaksi. Pengaman lama hilang, dan pengganti hanya melindungi bila dipasang; template bawaan memasangnya, sedangkan jenis rancangan sendiri harus memasangnya sendiri. Perubahan menyentuh Subledger (model pihak, pencatat dokumen, impor open item awal), `TransactionEntry` (skema field, validasi, lookup), SPA, OpenAPI, template, seeder demo, dan panduan; modul lain tidak memakai peran pihak.

**Perlu dibuktikan sebelum rute dengan profil sendiri dikerjakan:** rekonsiliasi dan laporan umur sekarang diuji dengan satu profil posting. Data uji sudah punya profil kedua (Karyawan, akun 1171). Bila open item belum mencatat akun kontrol dokumennya, rekonsiliasi per akun harus mengikutinya, dan itu ditambahkan lebih dulu (B-09b).

## Alternatif ditolak

- **Kotak centang tambahan** (`is_employee`, `is_shareholder`, dan seterusnya): tidak fleksibel dan mengulang masalah di tiap budaya usaha.
- **Peran sebagai label bebas tanpa pembatas di jenis transaksi:** kehilangan pengaman dan memaksa admin menyaring sendiri daftar pihak.
- **Satu catatan pihak per peran** (satu orang, banyak catatan): data ganda dan saldo gabungan orang itu tidak terlihat.
- **Menghapus profil posting per pihak, akun kontrol hanya per jenis transaksi:** kehilangan pemisahan bawaan per kelompok pihak yang sudah dipakai.
- **Mempertahankan `is_customer`/`is_supplier` sebagai turunan:** dua sumber kebenaran, dan tidak perlu karena belum ada konsumen.
