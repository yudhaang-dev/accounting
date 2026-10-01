# ADR-0003 — PostgreSQL

Status: Diterima (2026-10-01)

## Konteks

Invarian akuntansi (jurnal seimbang, jurnal posted immutable, periode tanpa tumpang tindih, relasi satu perusahaan) harus tetap benar walau ada bug aplikasi atau akses langsung ke database. `.env.example` starter kit masih memakai SQLite.

## Keputusan

Pakai PostgreSQL di semua lingkungan, termasuk test. Fitur yang dipakai:

- `NUMERIC` untuk uang dan kurs, tanpa floating point; skala efektif dari konfigurasi mata uang (P-08).
- Exclusion constraint (`btree_gist`) untuk mencegah periode tumpang tindih.
- Constraint trigger `DEFERRABLE INITIALLY DEFERRED` untuk menolak jurnal tidak seimbang saat commit.
- Trigger yang menolak `UPDATE`/`DELETE` pada tabel jurnal posted dan audit.
- Composite foreign key `(company_id, id)` agar relasi lintas perusahaan mustahil.
- Partial unique index untuk idempotensi dan satu jurnal penutup per tahun.
- Recursive CTE untuk hierarki akun dan dimensi; partisi tabel baris jurnal per tahun bila volume menuntut.

## Konsekuensi

Laragon perlu PostgreSQL, dan `.env.example` diubah ke `pgsql`. Test lebih lambat dibanding SQLite tetapi mewakili produksi.

## Alternatif ditolak

MySQL: tidak punya exclusion constraint dan constraint yang dapat ditunda. SQLite: tidak mewakili perilaku produksi.
