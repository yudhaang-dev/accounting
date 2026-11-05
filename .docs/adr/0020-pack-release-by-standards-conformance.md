# ADR-0020 — Rilis Paket Template Menurut Kesesuaian dengan Standar

Status: Diterima (2026-11-05). Mengganti butir 8 [ADR-0019](0019-business-type-template-packs.md).

## Konteks

ADR-0019 butir 8 menahan paket template (`reviewed: false`) sampai akuntan meninjau isinya. Tujuan paket adalah menyodorkan bagan akun, jenis transaksi, dan laporan yang mengikuti SAK EMKM, PSAK, dan ketentuan PPN yang sudah dirujuk di REFERENCES.md (R19, R20). Menunggu orang tertentu menjadi hambatan rilis, padahal acuannya sudah tertulis.

## Keputusan

1. Paket dirilis bila isinya sesuai dengan standar yang dirujuk di REFERENCES.md. Kesesuaian itu diperiksa pembuat paket terhadap rujukan, bukan menunggu tinjauan akuntan tertentu.
2. Flag `reviewed` pada manifes tetap ada sebagai gerbang rilis: `true` berarti paket sudah diperiksa terhadap rujukan; `false` tetap tidak tampil di daftar produksi.
3. Perlindungan bagi perusahaan tidak berubah: paket hanya saran, wajib ditinjau dan dikonfirmasi per komponen oleh orang yang memasang (P-01, X-AC-03), dan semua hasilnya data per perusahaan yang bisa diubah.
4. Paket bukan pernyataan kepatuhan. Penerapannya pada suatu perusahaan tetap tanggung jawab akuntan atau pemilik perusahaan itu.

## Konsekuensi

Paket dagang atau ritel, jasa, kontraktor, manufaktur ringan, F&B, dan keempat modul tambahan tampil dan bisa dipasang. Pemeriksaan oleh pembuat paket bukan tinjauan profesional; koreksi dari akuntan yang memakainya masuk lewat versi paket baru.
