# ADR-0008 — Valas di MVP

Status: Diterima (2026-10-01)

## Konteks

Mayoritas pasar memakai satu mata uang, tetapi eksportir dan importir juga menjadi target. Subledger piutang, utang, dan kas/bank berada di sistem ini, sehingga saldo valas, selisih kurs terealisasi, dan revaluasi pos moneter (PSAK 221 [R6]) tidak dapat diserahkan ke sistem sumber. Menambahkan valas belakangan berarti mengubah jurnal, open item, saldo, dan API sekaligus.

## Keputusan

- Baris jurnal dan open item menyimpan mata uang transaksi, nilai transaksi, kurs, dan nilai fungsional sejak MVP. Baris mata uang fungsional berkurs 1.
- Valas diaktifkan per perusahaan. Mata uang dan kurs milik `MasterData`; selisih kurs terealisasi milik `Subledger`; revaluasi oleh `GeneralLedger` (akun non-kontrol) dan `Subledger` (open item); akun selisih kurs dan pembulatan lewat peran akun GL.
- Satu mata uang fungsional per perusahaan. Translasi ke mata uang penyajian masuk konsolidasi (`Post`).

## Konsekuensi

Perusahaan yang hanya memakai satu mata uang tidak merasakan perbedaan. Validasi dan test bertambah untuk kurs, pembulatan, dan revaluasi.

## Alternatif ditolak

Hanya mata uang fungsional dengan konversi oleh sistem sumber: saldo dan open item valas tidak dapat dilacak, dan revaluasi tidak dapat dilakukan.
