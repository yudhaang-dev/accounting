# Chart of Accounts — Template COA & Peran Akun

> Data template, bukan kode (P-01). Akun dan pemetaan disimpan per perusahaan; kode yang sama di perusahaan lain adalah akun berbeda. Template perlu disesuaikan akuntan perusahaan dengan kegiatan usaha, kebijakan, materialitas, dan standar yang berlaku. Aturan akun ada di GL-FR-01 dan GL-FR-02.

## Konvensi template

- Kode 4 digit dengan rentang `1xxx` aset, `2xxx` liabilitas, `3xxx` ekuitas, `4xxx` pendapatan, `5xxx` biaya langsung/HPP, `6xxx` beban operasi, `71xx` penghasilan lain, `72xx` beban lain, `73xx` pajak penghasilan. Rentang hanya tata letak template; logika aplikasi tidak pernah membaca kode.
- Hanya akun daun menerima posting; induk dan anaknya berkelas sama. Normal: `D` debit, `K` kredit.
- **Kontrol** = akun yang hanya menerima sumber `subledger`. **Opsional** = aktifkan sesuai model bisnis.
- Template mensyaratkan dimensi `department` pada akun pendapatan/beban; perusahaan dapat mengubahnya per akun.
- Persediaan, HPP, pajak, gaji, dan penyusutan menerima nilai dari sistem sumber atau input (P-02).

## Peran akun

| Peran | Pemilik | Kardinalitas | Syarat | Saran |
| --- | --- | --- | --- | --- |
| `retained_earnings` | GL | 1 | Ekuitas, daun; wajib sebelum tutup tahun | 3130 |
| `fx_realized_gain`, `fx_unrealized_gain` | GL | 1 per peran | Pendapatan atau beban, daun; wajib bila valas aktif | 7130 |
| `fx_realized_loss`, `fx_unrealized_loss`, `fx_rounding` | GL | 1 per peran | Pendapatan atau beban, daun; wajib bila valas aktif | 7230 |
| `receivables_control` | SL | 1 (profil posting default) | Aset, daun, sumber `subledger` | 1121 |
| `payables_control` | SL | 1 (profil posting default) | Liabilitas, daun, sumber `subledger` | 2110 |
| `cash_equivalents` | RP | 1..n | Aset, daun; kelayakan dikonfirmasi perusahaan | 1111–1115 |

Peran menyimpan ID akun, versi, tanggal efektif, dan aktor; saran wajib dikonfirmasi (X-AC-03). `current_year_earnings` adalah nilai terhitung `Reporting`, bukan peran yang dipetakan, sehingga template tidak menyediakan akun "laba tahun berjalan". Penyajian laporan dan klasifikasi arus kas bukan peran: lihat `ReportDefinition` dan `MovementClassification` ([reporting.md](modules/reporting.md)).

## Akun

| Kode | Nama | Kelas | Normal | Catatan |
| --- | --- | --- | --- | --- |
| 1000 | ASET | `asset` | — | Induk |
| 1100 | Aset Lancar | `asset` | — | Induk |
| 1110 | Kas dan Setara Kas | `asset` | — | Induk |
| 1111 | Kas Kecil | `asset` | D | Kontrol; `CashAccount` jenis kas |
| 1112 | Bank Operasional | `asset` | D | Kontrol; `CashAccount` jenis bank |
| 1113 | Bank Khusus | `asset` | D | Opsional; kontrol |
| 1114 | Dana dalam Perjalanan | `asset` | D | Opsional; transfer antarrekening |
| 1115 | Bank Valas | `asset` | D | Opsional; kontrol; batasan mata uang asing; revaluasi |
| 1120 | Piutang Usaha | `asset` | — | Induk |
| 1121 | Piutang Usaha — Pihak Ketiga | `asset` | D | Kontrol; `receivables_control` |
| 1122 | Cadangan Kerugian Piutang | `asset` | K | Kontra; estimasi diinput akuntan [R7] |
| 1123 | Piutang Usaha — Pihak Berelasi | `asset` | D | Opsional; kontrol lewat profil posting |
| 1130 | Persediaan | `asset` | — | Induk |
| 1131 | Persediaan Barang Dagang | `asset` | D | Nilai dari sistem sumber [R4] |
| 1132 | Bahan/Perlengkapan untuk Jasa | `asset` | D | Opsional |
| 1140 | Pajak Dibayar di Muka | `asset` | — | Induk |
| 1141 | PPN Masukan | `asset` | D | Opsional |
| 1142 | Pajak Penghasilan Dibayar di Muka | `asset` | D | Opsional |
| 1150 | Biaya Dibayar di Muka | `asset` | D | Amortisasi lewat jurnal berulang |
| 1160 | Uang Muka kepada Pemasok | `asset` | D | Daun |
| 1170 | Piutang Lain-lain | `asset` | D | Bukan piutang pelanggan |
| 1200 | Aset Tidak Lancar | `asset` | — | Induk |
| 1210 | Aset Tetap — Harga Perolehan | `asset` | — | Induk; register aset di luar produk |
| 1211 | Tanah | `asset` | D | Opsional |
| 1212 | Bangunan dan Renovasi | `asset` | D | Opsional |
| 1213 | Peralatan dan Komputer | `asset` | D | Opsional |
| 1214 | Kendaraan | `asset` | D | Opsional |
| 1219 | Akumulasi Penyusutan | `asset` | K | Kontra |
| 1220 | Aset Takberwujud | `asset` | — | Induk |
| 1221 | Perangkat Lunak/Lisensi | `asset` | D | Opsional; sesuai kebijakan kapitalisasi |
| 1229 | Akumulasi Amortisasi | `asset` | K | Kontra; opsional |
| 1230 | Uang Jaminan Jangka Panjang | `asset` | D | Opsional |
| 2000 | LIABILITAS | `liability` | — | Induk |
| 2100 | Liabilitas Jangka Pendek | `liability` | — | Induk |
| 2110 | Utang Usaha — Pemasok | `liability` | K | Kontrol; `payables_control` |
| 2120 | Beban Masih Harus Dibayar | `liability` | K | Akrual |
| 2130 | Utang Lain-lain | `liability` | K | Daun |
| 2140 | Uang Muka Pelanggan/Pendapatan Ditangguhkan | `liability` | K | Diakui sebagai pendapatan saat kewajiban dipenuhi |
| 2150 | Utang Pajak | `liability` | — | Induk |
| 2151 | PPN Keluaran | `liability` | K | Opsional |
| 2152 | Utang Pemotongan/Pemungutan Pajak | `liability` | K | Opsional |
| 2153 | Utang Pajak Penghasilan | `liability` | K | Opsional |
| 2160 | Pinjaman Jangka Pendek | `liability` | K | Opsional |
| 2170 | Bagian Lancar Pinjaman/Sewa | `liability` | K | Opsional |
| 2200 | Liabilitas Jangka Panjang | `liability` | — | Induk |
| 2210 | Pinjaman Jangka Panjang | `liability` | K | Opsional; revaluasi bila valas |
| 2220 | Liabilitas Sewa Jangka Panjang | `liability` | K | Opsional |
| 2230 | Liabilitas Imbalan Kerja | `liability` | K | Opsional [R8] |
| 3000 | EKUITAS | `equity` | — | Induk |
| 3110 | Modal Disetor/Modal Pemilik | `equity` | K | Daun |
| 3120 | Tambahan Modal Disetor | `equity` | K | Opsional |
| 3130 | Saldo Laba | `equity` | K | `retained_earnings` |
| 3150 | Dividen/Prive | `equity` | D | Kontra; sesuai bentuk badan usaha |
| 3160 | Penghasilan Komprehensif Lain | `equity` | K | Opsional; akumulasi OCI [R8] |
| 4000 | PENDAPATAN | `income` | — | Induk |
| 4100 | Pendapatan Operasional | `income` | — | Induk |
| 4110 | Penjualan Barang | `income` | K | Daun |
| 4120 | Pendapatan Jasa | `income` | K | Daun |
| 4130 | Pendapatan Operasional Lain | `income` | K | Daun |
| 4190 | Retur dan Pengurang Pendapatan | `income` | — | Induk |
| 4191 | Retur/Pengurangan Penjualan | `income` | D | Kontra |
| 4192 | Diskon Penjualan | `income` | D | Kontra |
| 5000 | BIAYA LANGSUNG / HPP | `expense` | — | Induk |
| 5100 | HPP Barang | `expense` | — | Induk |
| 5110 | Harga Pokok Barang Terjual | `expense` | D | Nilai dari sistem sumber |
| 5120 | Selisih/Penyusutan Persediaan | `expense` | D | Opsional |
| 5200 | Biaya Langsung Jasa/Proyek | `expense` | — | Induk |
| 5210 | Tenaga Kerja Langsung | `expense` | D | Daun |
| 5220 | Subkontraktor/Penyedia Jasa Langsung | `expense` | D | Daun |
| 5230 | Bahan Langsung | `expense` | D | Daun |
| 5240 | Biaya Langsung Proyek Lain | `expense` | D | Daun |
| 6000 | BEBAN OPERASIONAL | `expense` | — | Induk |
| 6100 | Penjualan dan Pemasaran | `expense` | — | Induk |
| 6110 | Iklan dan Promosi | `expense` | D | Daun |
| 6120 | Komisi Penjualan | `expense` | D | Daun |
| 6130 | Pengiriman kepada Pelanggan | `expense` | D | Daun |
| 6200 | Umum dan Administrasi | `expense` | — | Induk |
| 6210 | Gaji dan Tunjangan | `expense` | D | Nilai dari sistem payroll |
| 6220 | Sewa Kantor | `expense` | D | Daun |
| 6230 | Utilitas dan Komunikasi | `expense` | D | Daun |
| 6240 | Perlengkapan Kantor | `expense` | D | Daun |
| 6250 | Perjalanan Dinas | `expense` | D | Daun |
| 6260 | Jasa Profesional | `expense` | D | Daun |
| 6270 | Langganan Perangkat Lunak | `expense` | D | Daun |
| 6280 | Biaya Administrasi Bank | `expense` | D | Daun |
| 6290 | Beban Imbalan Kerja | `expense` | D | Opsional [R8] |
| 6300 | Penyusutan, Amortisasi, dan Penurunan Nilai | `expense` | — | Induk |
| 6310 | Beban Penyusutan | `expense` | D | Lewat jurnal berulang atau sistem sumber |
| 6320 | Beban Amortisasi | `expense` | D | Daun |
| 6330 | Beban Kerugian Piutang | `expense` | D | Daun |
| 7100 | PENGHASILAN LAIN | `income` | — | Induk |
| 7110 | Penghasilan Bunga | `income` | K | Opsional |
| 7120 | Keuntungan Pelepasan Aset | `income` | K | Opsional |
| 7130 | Laba Selisih Kurs | `income` | K | Peran laba selisih kurs; wajib bila valas aktif |
| 7200 | BEBAN LAIN | `expense` | — | Induk |
| 7210 | Beban Bunga | `expense` | D | Opsional |
| 7220 | Kerugian Pelepasan Aset | `expense` | D | Opsional |
| 7230 | Rugi Selisih Kurs | `expense` | D | Peran rugi selisih kurs dan `fx_rounding`; wajib bila valas aktif |
| 7300 | BEBAN PAJAK PENGHASILAN | `expense` | — | Induk |
| 7310 | Beban Pajak Kini | `expense` | D | Opsional |
| 7320 | Beban/Manfaat Pajak Tangguhan | `expense` | D | Opsional; dapat bersaldo kredit |

## Saran definisi laporan

Saran berikut menjadi `ReportDefinition` template; perusahaan dapat mengubahnya.

1. **Laba rugi:** pendapatan (4100) dikurangi pengurang pendapatan (4190) dan biaya langsung (5000) untuk laba kotor; dikurangi beban operasi (6000); penghasilan dan beban lain (7100, 7200); pajak penghasilan (7300). Bagian OCI memakai mutasi akun 3160.
2. **Neraca:** aset (1000), liabilitas (2000), ekuitas (3000), ditambah baris terhitung laba tahun berjalan.
3. **Arus kas:** `cash_equivalents` = 1111–1115; klasifikasi dari baris lawan dan key Jenis Transaksi, bukan dari akun bank [R3].
4. **Akun kontrol:** 1111–1115, 1121, 1123, dan 2110 hanya menerima sumber `subledger`.
5. **Pajak:** akun pajak hanya wadah buku besar; tarif, perlakuan, dan pelaporan pajak mengikuti status usaha dan ditinjau ahli pajak.

## Contoh jurnal dari nilai sumber

| Peristiwa | Debit | Kredit | Catatan |
| --- | --- | --- | --- |
| Jual barang kredit 1.000; HPP 600 | 1121 Piutang 1.000; 5110 HPP 600 | 4110 Penjualan 1.000; 1131 Persediaan 600 | HPP dipasok sistem sumber |
| Invoice jasa kredit 800 | 1121 Piutang 800 | 4120 Pendapatan Jasa 800 | Proyek bila terkait |
| Subkontraktor proyek 200 secara kredit | 5220 Subkontraktor 200 | 2110 Utang Usaha 200 | Departemen dan proyek |
| Pelanggan membayar invoice 800 | 1112 Bank 800 | 1121 Piutang 800 | Alokasi ke open item |
| Pelunasan invoice USD 1.000 (kurs invoice 15.000, kurs bayar 15.500) | 1115 Bank Valas 15.500.000 (USD 1.000) | 1121 Piutang 15.000.000 (USD 1.000); 7130 Laba Selisih Kurs 500.000 | Selisih kurs terealisasi (SL-FR-06) |
