# Planning — Anggaran & Proyeksi

> Modul `Planning` (prefix `PL`). Bergantung pada `GeneralLedger` dan `MasterData`. Perbandingan dengan realisasi dihitung `Reporting` (RP-FR-11).

## Tujuan & batas

Menyimpan rencana pendapatan dan biaya per periode, akun, dan dimensi dalam beberapa versi. Rencana tidak pernah menjadi jurnal.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `Plan` | Nama, tahun buku, deskripsi |
| `PlanVersion` | Nama versi (mis. anggaran awal, forecast Q2), status `draft`/`published`/`archived`, versi asal, penerbit dan waktu terbit |
| `PlanLine` | Periode, akun, `dimension_set_id`, nilai dalam mata uang fungsional, catatan |
| `PlanningPolicy` | Versi pembanding default per tahun buku; syarat penerbit berbeda dari pengubah terakhir |

## Interface publik

- `PlanReader` — baris rencana suatu versi per periode, akun, dan dimensi, untuk `Reporting`.

## Kebutuhan

- **PL-FR-01 Versi.** Satu rencana dapat memiliki banyak versi bernama. Versi `published` tidak dapat diubah; mengubahnya membuat versi `draft` baru yang menyalin isinya. Histori versi tidak pernah ditimpa.
- **PL-FR-02 Baris rencana.** Setiap baris memuat periode GL, akun daun aktif berkelas pendapatan atau beban, set dimensi, dan nilai desimal dalam mata uang fungsional. Baris tanpa proyek adalah aktivitas umum.
- **PL-FR-03 Input massal.** Baris dapat diimpor dan diekspor lewat CSV dengan validasi per baris. Versi baru SHOULD dapat disalin dari versi lain atau dari realisasi periode tertentu sebagai titik awal.
- **PL-FR-04 Penerbitan.** Menerbitkan versi memerlukan permission; kebijakan dapat mensyaratkan penerbit berbeda dari pengubah terakhir. Hanya versi terbit yang menjadi pembanding default, dan kebijakan menetapkan versi default per tahun buku.

## Kriteria penerimaan

- **PL-AC-01** Mengubah versi terbit menghasilkan versi draf baru; nilai versi terbit tetap.
- **PL-AC-02** Saat impor, baris dengan akun induk, akun neraca, periode di luar tahun buku, atau dimensi tidak valid ditolak per baris dengan alasannya.
- **PL-AC-03** Menerbitkan tanpa permission ditolak; bila kebijakan aktif, pengubah terakhir tidak dapat menerbitkan versinya sendiri.
