# Planning — Anggaran & Proyeksi

> Modul `Planning` (prefix `PL`). Bergantung pada `GeneralLedger` dan `MasterData`. Perbandingan dengan realisasi dihitung `Reporting` (RP-FR-11).

## Tujuan & batas

Menyimpan rencana pendapatan dan biaya per periode, akun, dan dimensi dalam beberapa versi, baik anggaran umum maupun estimasi satu kegiatan (keputusan, strategi, taktik) yang kemudian dibandingkan dengan pembukuan sebagai tolok ukur evaluasi ([ADR-0017](../adr/0017-activity-plans.md)). Rencana tidak pernah menjadi jurnal dan tidak membatasi pencatatan.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `Plan` | Judul, tujuan, tanggal mulai dan selesai, ruang lingkup opsional (satu nilai dimensi), deskripsi |
| `PlanVersion` | Nama versi (mis. anggaran awal, forecast Q2), status `draft`/`published`/`archived`, versi asal, penerbit dan waktu terbit |
| `PlanLine` | Periode, akun, `dimension_set_id`, nilai dalam mata uang fungsional, catatan |
| `PlanningPolicy` | Versi pembanding default per tahun buku; syarat penerbit berbeda dari pengubah terakhir |

## Interface publik

- `PlanReader` — baris rencana suatu versi per periode, akun, dan dimensi, serta periode dan ruang lingkup rencana, untuk `Reporting`.

## Kebutuhan

- **PL-FR-01 Rencana dan versi.** Rencana memuat judul, tujuan atau hipotesis yang dievaluasi, tanggal mulai dan selesai (boleh lintas tahun buku), dan ruang lingkup opsional berupa satu nilai dimensi; tanpa ruang lingkup berarti seluruh perusahaan. Ruang lingkup dipilih saat rencana dibuat dan tidak berubah sesudah versi pertama terbit. Satu rencana dapat memiliki banyak versi bernama (estimasi awal, revisi, aktual). Versi `published` tidak dapat diubah; mengubahnya membuat versi `draft` baru yang menyalin isinya. Histori versi tidak pernah ditimpa.
- **PL-FR-02 Baris rencana.** Setiap baris memuat periode GL di dalam periode rencana, akun daun aktif berkelas pendapatan atau beban, set dimensi, dan nilai desimal dalam mata uang fungsional. Baris tanpa proyek adalah aktivitas umum.
- **PL-FR-03 Input massal.** Baris dapat diimpor dan diekspor lewat CSV dengan validasi per baris. Versi baru SHOULD dapat disalin dari versi lain atau dari realisasi periode tertentu sebagai titik awal.
- **PL-FR-04 Penerbitan.** Menerbitkan versi memerlukan permission; kebijakan dapat mensyaratkan penerbit berbeda dari pengubah terakhir. Hanya versi terbit yang menjadi pembanding default, dan kebijakan menetapkan versi default per tahun buku untuk laporan umum; layar evaluasi (RP-FR-11) memilih versi pembandingnya sendiri.

## Kriteria penerimaan

- **PL-AC-01** Mengubah versi terbit menghasilkan versi draf baru; nilai versi terbit tetap.
- **PL-AC-02** Saat impor, baris dengan akun induk, akun neraca, periode di luar periode rencana atau di luar kalender fiskal, atau dimensi tidak valid ditolak per baris dengan alasannya. Rencana lintas tahun buku menerima baris di kedua tahun. Rencana yang sudah ada mendapat periode sama dengan tahun buku asalnya dan tanpa ruang lingkup.
- **PL-AC-03** Menerbitkan tanpa permission ditolak; bila kebijakan aktif, pengubah terakhir tidak dapat menerbitkan versinya sendiri.
