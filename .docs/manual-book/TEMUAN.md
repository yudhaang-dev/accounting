# Temuan uji ujung ke ujung

Diuji lewat Chrome di `http://accounting.test`, database kosong (`migrate:fresh`), perusahaan PT Sinar Nusantara Perkasa. Urutan mengikuti [PANDUAN-AKUNTAN-SETUP.md](../PANDUAN-AKUNTAN-SETUP.md). Semua temuan dikumpulkan dulu, perbaikan sesudah seluruh alur selesai.

| # | Bagian | Temuan | Tingkat |
| --- | --- | --- | --- |
| 1 | 1–4 Profil | Form "Ruang kerja baru" tidak menanyakan nama badan hukum dan NPWP; isinya baru bisa diisi lewat tombol "Ubah" pada langkah 1 yang sudah bercentang. Panduan menyebut keduanya diisi saat membuat perusahaan. | Rendah |
| 2 | 1–4 Profil | Ringkasan profil di wizard hanya menampilkan nama, mata uang, dan zona waktu; nama badan hukum dan NPWP yang baru disimpan tidak terlihat. | Rendah |
| 3 | 1–4 Profil | Setelah perusahaan aktif, Beranda hanya menampilkan profil tanpa tombol ubah (formulir profil hanya dipakai di halaman penyiapan). Nama badan hukum dan NPWP tidak bisa diperbaiki lagi lewat layar. | Sedang |
| 4 | 1–4 Bagan akun | Kotak konfirmasi "Saya telah meninjau daftar akun" tidak tercentang saat diklik lewat elemen akses (hanya klik koordinat yang berhasil). Kemungkinan masalah alat uji, belum dipastikan sebagai bug aplikasi. | Perlu dicek |
| 5 | 5, 8 Dimensi | Tipe dimensi "Department" (bertingkat) dan "Project" sudah ada pada perusahaan baru, berlabel Inggris dan kosong. Panduan bagian 8 menyuruh membuatnya dari nol dan tidak menyebut tipe bawaan ini. | Rendah |
| 6 | 6 Buku besar | Kebijakan periode tutup sementara menampilkan kunci mentah `system.closing` dan `system.fx_revaluation` tanpa penjelasan arti. | Rendah |
| 7 | 9 Profil posting | Kolom "Berlaku sejak" pemetaan akun kontrol menampilkan `1970-01-01` dalam format mentah. | Rendah |
| 8 | 9 Profil posting | Pilihan akun kontrol piutang memuat juga akun kas (1111, 1112); tidak disaring menurut peran. | Rendah |
| 9 | 10 Kas dan bank | Pilihan akun buku besar rekening bank memuat juga akun piutang (1121, 1123). Rekening bank bisa tertaut ke akun piutang. | Sedang |
| 10 | 11 Jenis transaksi | **Kendala yang dilaporkan terbukti.** Form "Entri baru" memilih jenis pertama menurut kunci teknis (`adjustment`, yaitu Jurnal Penyesuaian, jenis baris bebas) sebagai bawaan, sehingga yang muncul form jurnal umum. Admin yang tidak memperhatikan dropdown mengisi form yang salah. Setelah jenis dipindah ke jenis terstruktur, form tampil benar. | Tinggi |
| 11 | 11 Jenis transaksi | Urutan dropdown "Jenis transaksi" di form entri mengikuti kunci teknis (Jurnal Penyesuaian, Transfer Antar Kas, Penjualan Kredit, Terima Pembayaran, Jurnal Umum, ...), bukan abjad label atau kekerapan pakai. | Sedang |
| 12 | 11 Jenis transaksi | Field "Akun pendapatan" di Penjualan Kredit hasil template menawarkan semua akun, termasuk 6120 Komisi Penjualan (beban) dan akun retur/diskon; panduan menyuruh mempersempitnya sebelum dipakai admin. Belum ada petunjuk di layar. | Sedang |
| 13 | Entri | Daftar "Pelanggan" di form Penjualan Kredit memuat juga pemasok (CV Sumber Makmur); tidak disaring menurut peran pihak. | Sedang |
| 14 | Entri | Nomor entri (`CREDIT-SALE/2026/10/000001`) diberikan saat draf dibuat (bawaan template: "Saat entri dibuat"), sedangkan panduan bagian 11 menganjurkan saat diajukan agar urutan bebas dari draf batal. Pemisahan tugas juga belum aktif bawaan: pengaju bisa menyetujui entrinya sendiri. | Rendah |
| 15 | Entri | Pilihan open item pada field alokasi menampilkan sisa dengan enam desimal ("11.100.000,000000 IDR"), padahal tampilan lain sudah dua desimal. | Rendah |
| 16 | Navigasi | Alamat tak dikenal di bawah ruang kerja yang sah (mis. `/w/<id>/trial-balance`) menampilkan "Ruang kerja tidak ditemukan... atau Anda belum menjadi anggotanya", menyesatkan; seharusnya "halaman tidak ditemukan". | Rendah |
| 17 | 12 Kebijakan persetujuan | Teks bantuan menampilkan kunci izin mentah `entries.approve` alih-alih nama izin yang dimengerti pengguna. | Rendah |
| 18 | 11 Jenis transaksi | **Penyebab lain kendala form entri.** Jenis baru langsung punya Versi 1 berstatus Terbit bermode Baris bebas (kosong). Jenis itu sudah muncul di dropdown form entri sebagai jurnal bebas, padahal perancangnya belum dibuka. Setelah versi terstruktur terbit, versi 1 tetap Terbit berdampingan. | Tinggi |
| 19 | Pihak | Peran pihak hanya Pelanggan dan Pemasok. Tidak ada peran Karyawan, sehingga field "Karyawan" pada Kasbon memuat pelanggan dan pemasok. Template COA juga tidak punya akun Piutang Karyawan (hanya 1170 Piutang Lain-lain). | Sedang |
| 20 | 11 Jenis transaksi | Jenis dengan aturan "akun tetap" ke akun kas (khusus Subledger) lolos diterbitkan, tetapi entrinya ditolak saat diajukan: "Akun 1112 tidak menerima posting dari sumber "entry"" (sumber ditampilkan dengan kata teknis). Perancang tidak memperingatkan. Field "Dibayar dari rekening" tidak berpengaruh pada jurnal bila aturan memakai akun tetap, dan field yang tidak dipakai aturan mana pun lolos terbit. | Tinggi |
| 21 | 11 Jenis transaksi | Galat penerbitan "setiap aturan yang berbagi field harus menyatakannya dengan `reuses_field`" memakai nama teknis dan tampil tiga kali berulang ("and 1 more error" tidak diterjemahkan). | Sedang |
| 22 | 11 Jenis transaksi | **Simpan draf gagal diam-diam.** Server menjawab 422 untuk `posting_rules.1.account`, tetapi tidak ada pesan di layar (tidak di banner, tidak di dekat field, tidak sebagai toast). Tombol Terbitkan tetap bisa dipakai dan menerbitkan isi draf tersimpan yang lama, tanpa peringatan bahwa form belum tersimpan. Akibatnya Versi 3 dan 4 terbit identik dengan Versi 2 padahal form menampilkan rute dan aturan baru. | Tinggi |
| 23 | 11 Jenis transaksi | **Kasbon karyawan tidak bisa dimodelkan.** Rute `invoice` ditolak server bila ada aturan ke rekening kas ("Dokumen berjenis invoice tidak menggerakkan kas..."); rute `payment` dengan akun kontrol piutang berarti penerimaan (debit kas), dengan akun kontrol utang berarti pembayaran ke akun liabilitas. Kas keluar yang menambah piutang (kasbon, pinjaman ke pihak) tidak punya jalan di sistem; entri manual ke akun kas juga ditolak (temuan 20). Lihat bagian "Keputusan yang dibutuhkan". | Tinggi |

## Temuan 23 selesai: kasbon karyawan (commit 09c8f73, B-08)

Dipilih pilihan 1: jenis dokumen subledger baru `loan` ("Pinjaman atau kasbon") yang menaikkan saldo sekaligus menggerakkan kas, lengkap dengan template, dokumen, dan test. Terbukti di browser: Jenis Transaksi Kasbon Karyawan versi 5 (rute `loan`) menghasilkan entri `KASBON-KARYAWAN/2026/10/000002`, diposting sebagai jurnal `JU/2026/10/000003` (Debit 1171 Piutang Karyawan, Kredit 1112 Bank), dengan open item sisi debit Rp2.000.000 untuk Siti Rahma dan transaksi kas keluar. Temuan 18, 20, 21, dan 22 masih menunggu perbaikan.

Pilihan yang dipertimbangkan sebelum keputusan (arsip):

1. **Tambah rute subledger untuk kas keluar yang menambah piutang** (misalnya jenis dokumen "pinjaman" atau izin `invoice` bergerak ke kas). Paling bersih, tetapi perubahan di Subledger dan butuh keputusan desain.
2. **Pakai `payment` dengan akun kontrol utang** dan akun liabilitas "Kasbon Karyawan" yang bersaldo debit. Bisa dikerjakan sekarang tanpa perubahan kode, tetapi neraca menampilkan liabilitas bersaldo debit sehingga perlu reklasifikasi saat penyajian.
3. **Longgarkan akun kas** (izinkan sumber `manual` pada akun kas tertentu) lalu pakai jenis dengan akun tetap. Cepat, tetapi melonggarkan pengaman bahwa kas hanya bergerak lewat Subledger.

Rekomendasi: pilihan 1, dengan pilihan 2 sebagai solusi sementara yang didokumentasikan di manual.

Keadaan data uji saat ini: jenis "Kasbon Karyawan" punya versi 1 sampai 4 berstatus Terbit (v2 sampai v4 identik, tanpa rute) dan draf v5 dengan rute yang tidak bisa disimpan; akun 1171 Piutang Karyawan, profil posting "Karyawan", dan pihak "Siti Rahma" sudah dibuat; entri `KASBON-KARYAWAN/2026/10/000001` berstatus Draf dan tidak bisa diajukan.

## Hasil yang sudah terbukti jalan

- Penyiapan perusahaan dari database kosong sampai aktif (profil, 101 akun, FY2026–FY2027, aktivasi).
- Pembatasan akun kas dan kontrol ke sumber Subledger; peran Saldo laba; kebijakan buku besar versi 1; profil posting Umum dengan akun kontrol piutang (1121) dan utang (2110); dua pihak; rekening bank dan kas.
- Impor template Jenis Transaksi (6 harian + 4 umum) dan template definisi laporan (3).
- Penjualan Kredit → ajukan → setujui → posting (jurnal `JU/2026/10/000001`); dokumen dan open item terbentuk.
- Terima Pembayaran dengan alokasi ke faktur → posting; Neraca saldo seimbang, Neraca seimbang (Aset = Liabilitas + Ekuitas), Umur piutang kosong.

## Belum diuji

Kebijakan persetujuan dan pemisahan tugas (butuh anggota kedua), anggota dan role, kebijakan keamanan, klien integrasi, jenis transaksi sendiri (`structured`) lewat perancang, pembalikan jurnal, tutup periode dan tahun buku, saldo konversi, mata uang dan kurs, rekonsiliasi.
