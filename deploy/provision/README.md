# Penyiapan server (D4)

Skrip yang dijalankan di server produksi/demo (Ubuntu 22.04, satu mesin dengan situs lain), urut nomor, sebagai root: `ssh user@host 'sudo bash -s' < berkas.sh`. Semuanya idempoten dan hanya menambah; yang bisa gagal menguji konfigurasi lebih dulu (`nginx -t`, `php-fpm -t`) dan mengembalikan berkasnya. Tidak ada rahasia di sini: kata sandi database dibuat di server (`/etc/accounting/*.db.pass`) dan kunci SMTP diisi pemilik di `/etc/accounting/mail.env`.

| Berkas | Isi |
| --- | --- |
| `01-backup-and-swap.sh` | arsip `/etc/nginx`, `/etc/php`, swap 2 GB |
| `02-postgresql.sh` | PostgreSQL 18 (PGDG), hanya localhost, dua database dan role (`accounting`, `accounting_staging`), `max_locks_per_transaction=256` |
| `03-php-fpm-pools.sh` | pengguna sistem, direktori `/var/www/<env>/{releases,shared,current}`, satu pool FPM per lingkungan |
| `04-nginx.sh` | vhost di belakang Cloudflare (IP klien dari `CF-Connecting-IP`), sertifikat sementara |
| `05-certificates.sh` | Let's Encrypt lewat webroot (`dry-run`, lalu `issue`) |
| `06-rename-staging-to-demo.sh` | domain lingkungan kedua menjadi `demo-accounting.vcare.web.id`; argumen: email kontak akun Let's Encrypt |
| `07-mail.sh` | templat `/etc/accounting/mail.env` (Brevo SMTP) dan `accounting-mail-test` |
| `08-backups.sh` | `pg_dump` harian (14 harian, 8 mingguan), uji restore mingguan, timer systemd |
| `09-queue-and-scheduler.sh` | worker `queue:work` dan `schedule:run` tiap menit per lingkungan; menyala sendiri begitu `current/artisan` ada |

Nama domain dan IP tertulis di skrip untuk server yang sekarang; ganti bila berpindah.

## Sesudah deploy

- Rilis baru: letakkan di `releases/`, ganti symlink `current`, `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart` (worker keluar dan systemd menjalankannya pada kode baru). Kali pertama: `systemctl restart accounting-queue-<env>` bila worker belum menyala.
- Salin `/etc/accounting/mail.env` ke `.env` aplikasi; isi `DB_PASSWORD` dari `/etc/accounting/<db>.db.pass`.
- `php artisan passport:keys` di server (jangan disalin dari lokal).
- Ulangi uji restore (`sudo -u postgres accounting-backup verify`) setelah migrasi: sebelum itu database kosong dan pemeriksaan jurnal belum berarti.
- Berkas lampiran (`shared/storage`) belum ada di cadangan.
