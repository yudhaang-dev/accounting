# ADR-0001 — Konvensi Bahasa

Status: Diterima (2026-10-01)

## Konteks

Kode dibaca developer dan coding agent; UI dipakai user Indonesia; dokumen dipakai pemilik produk untuk memahami konteks. Campuran bahasa di kode membuat penamaan tidak konsisten dan menambah biaya token.

## Keputusan

- **Bahasa Inggris** untuk namespace, class, method, variabel, tabel dan kolom DB, nilai enum/status, path dan field API, permission key, key Jenis Transaksi sistem, kode error, nama event, config key, nama test, komentar kode, dan pesan commit.
- **Bahasa Indonesia** hanya untuk teks tampilan, tidak pernah sebagai literal di kode: di SPA lewat file terjemahan SPA, di backend (mis. `message` API, email) lewat `lang/id`. `message` API diterjemahkan menurut `Accept-Language`; locale default dari konfigurasi, lalu `default_locale` perusahaan dan preferensi user.
- Data bisnis yang diinput perusahaan (nama akun, label Jenis Transaksi, nama dimensi) boleh berbahasa Indonesia karena itu data, bukan kode.
- Dokumen di `.docs/` berbahasa Indonesia dengan identifier Inggris dari glosarium [PRODUCT.md](../PRODUCT.md) §4. Istilah baru ditambahkan ke glosarium sebelum dipakai di kode.

## Konsekuensi

Penamaan konsisten untuk manusia dan agent. Setiap teks UI memerlukan entri terjemahan, dan glosarium harus dirawat.
