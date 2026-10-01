# ADR-0006 — Jenis Transaksi sebagai Klasifikasi Jurnal; Entri Terpisah dari Ledger

Status: Diterima (2026-10-01)

## Konteks

Draft awal menaruh jurnal manual dan approval di General Ledger, sedangkan event masuk lewat modul intake. Kebutuhan seperti periode penyesuaian, kategori jurnal, dan koreksi periode lalu cenderung dijawab dengan konsep baru satu per satu.

## Keputusan

- Setiap jurnal membawa key dan versi Jenis Transaksi. Jurnal manual adalah Jenis Transaksi mode `free_lines`; jurnal sistem memakai key `system.*`.
- Draf, approval, dan idempotensi berada di `TransactionEntry`. `GeneralLedger` hanya menyimpan jurnal posted (append-only).
- Key Jenis Transaksi menjadi pegangan konfigurasi untuk penomoran, approval/SoD, izin posting saat `soft_closed`, filter laporan, serta klasifikasi arus kas dan perubahan ekuitas.
- GL menyimpan key sebagai metadata opak, sehingga `Reporting` dapat mengklasifikasikan tanpa bergantung pada `TransactionEntry`.

## Konsekuensi

Satu konsep klasifikasi menggantikan beberapa konsep. Koreksi audit cukup berupa Jenis Transaksi yang diizinkan memposting saat `soft_closed`. Nomor entri dan nomor jurnal dapat berbeda.

## Alternatif ditolak

Periode ke-13 untuk penyesuaian, karena `soft_closed` dan izin per key sudah mencukupi. Field kategori jurnal terpisah, karena menduplikasi Jenis Transaksi.
