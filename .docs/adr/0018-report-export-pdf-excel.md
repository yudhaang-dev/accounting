# ADR-0018 — Ekspor Laporan ke PDF dan Excel

Status: Diterima (2026-11-05)

## Konteks

X-FR-02 hanya menjanjikan CSV. Akuntan dan pemilik usaha menyerahkan Neraca, Laba Rugi, dan Arus Kas kepada pihak lain (bank, pemilik, pemeriksa) sebagai PDF yang rapi dan Excel yang bisa diolah. CSV tidak memuat kop, format angka, atau lebar kolom, dan Excel membaca CSV menurut pengaturan regional pembukanya.

Pilihan yang ditimbang: (a) cetak dari browser dengan stylesheet cetak, (b) render di klien (SPA) dengan pustaka JS, (c) render di server. (a) dan (b) tidak dapat dipakai klien integrasi (API-first, P-02), tidak ikut otorisasi dan cakupan data yang dijaga server (X-AC-01), dan hasilnya bergantung pada browser pembaca. Maka (c).

## Keputusan

1. **Server menghasilkan berkasnya**, lewat endpoint laporan yang sama dengan `Accept` berbeda: `application/pdf` dan `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, di samping `text/csv`. Otorisasi, cakupan data, filter, dan dimensi identik dengan sumbernya (X-FR-02); tidak ada endpoint ekspor terpisah.
2. **Satu model tabel, tiga penerjemah.** `Platform/Export` mendapat model tabel netral (baris kop, header kolom, baris dengan tipe sel: teks, angka, dan penanda tebal atau indentasi) dengan penerjemah CSV (sudah ada), XLSX, dan PDF. Setiap laporan memetakan hasilnya ke model itu satu kali. Angka sama dengan layar karena berasal dari hasil API yang sama; di Excel berupa **sel angka** dengan format ribuan, bukan teks.
3. **Pustaka.** XLSX dengan `openspout/openspout` (streaming, tanpa ekstensi di luar `zip`, yang sudah ada). PDF dengan `dompdf/dompdf` (PHP murni, tanpa Chromium atau binari eksternal, agar jalan di Windows dan Linux produksi yang sama). Keduanya dipakai hanya di `Platform/Export`; modul lain tidak mengimpornya (ADR-0002).
4. **Kop** memuat nama perusahaan, nama laporan, periode atau tanggal, mata uang fungsional, filter yang aktif, dan waktu pembuatan. PDF juga memuat nomor halaman dan mengulang header kolom di tiap halaman. Penanda `restricted` (cakupan data) dan cakupan histori parsial ikut tercetak, karena laporan tanpa penanda itu menyesatkan bila lepas dari layar.
5. **Ukuran.** Pada cakupan awal ekspor berjalan sinkron dengan batas baris (konfigurasi, awal 5.000 baris). Melebihi batas, API menjawab 422 dengan kode khusus dan saran mempersempit rentang atau memakai CSV (yang tetap streaming). Ekspor asinkron dengan tautan unduh (API-TR-09) ditunda sampai ada laporan yang melampaui batas itu pada data nyata; keputusan itu butuh ADR tersendiri karena memerlukan penyimpanan berkas dan masa berlaku tautan.
6. **Cakupan awal:** Neraca, Laba Rugi, Arus Kas tidak langsung (definisi laporan, termasuk baris rincian akun, RP-AC-12), neraca saldo, dan buku besar. Laporan lain tetap CSV sampai diminta.
7. **Bahasa** kop dan label mengikuti `Accept-Language` seperti respons JSON (API-TR-06).

### Yang perlu dibuktikan saat implementasi, bukan diasumsikan

- Dompdf memuat huruf Latin dengan benar untuk nama akun berbahasa Indonesia dan tidak memerlukan font tambahan; tabel lebar (banyak kolom pembanding) dicetak lanskap tanpa terpotong. Terbukti di penampil PDF Chrome: Latin beraksen, Yunani, dan Sirilik tampil benar; Arab tampil tetapi hurufnya tidak tersambung, dan huruf CJK tampil sebagai kotak kosong karena DejaVu Sans tidak memuatnya. Nama akun dengan huruf CJK atau Arab perlu Excel atau CSV, atau font tambahan (keputusan baru).
- Hasil XLSX dibuka tanpa peringatan, sel angka berupa angka, dan sel teks yang diawali `=`, `+`, `-`, `@` tidak dieksekusi sebagai rumus (sama seperti perlindungan di `CsvExport`).
- Jumlah sel rincian akun sama dengan nilai baris di berkas (RP-AC-12).
- Memori dan waktu pada neraca saldo dan buku besar mendekati batas baris.

## Konsekuensi

Menambah dua dependensi Composer, terbatas di `Platform/Export`. Sumber kebenaran angka tetap satu (hasil laporan), sehingga PDF, Excel, CSV, dan layar tidak bisa berbeda. Klien integrasi mendapat format yang sama dengan SPA. Perubahan menyentuh `Platform/Export`, pengendali laporan, spesifikasi OpenAPI (media type respons), SPA (tombol ekspor menawarkan tiga format), dan panduan. Ekspor besar asinkron sengaja belum dikerjakan; batas baris menjaga server dari ekspor yang tak terkendali di sementara waktu.
