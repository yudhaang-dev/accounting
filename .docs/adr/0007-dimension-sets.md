# ADR-0007 — Dimension Set

Status: Diterima (2026-10-01)

## Konteks

Draft hanya mengenal departemen dan proyek, sementara banyak perusahaan juga memerlukan cabang, lini produk, atau kanal. Jumlah dimensi tidak boleh di-hardcode (P-01).

## Keputusan

- Tipe dimensi adalah data per perusahaan; `department` dan `project` hanya seed.
- Setiap kombinasi unik nilai dimensi disimpan sekali sebagai `dimension_set_id` di `MasterData`. Baris jurnal, saldo teragregasi, dan baris rencana hanya menyimpan ID tersebut.
- Filter per dimensi memakai join ke isi set yang terindeks; API mengirim `dimensions` sebagai objek key tipe → ID nilai.

## Konsekuensi

Dimensi baru tidak memerlukan perubahan skema. Query per dimensi memerlukan join tambahan, tetapi agregasi tetap cepat karena saldo dikelompokkan per set.

## Alternatif ditolak

Kolom tetap `dim1..dimN` (batas jumlah dimensi adalah hardcode). JSONB per baris (integritas lemah dan agregasi lambat). EAV per baris (jumlah baris membengkak).
