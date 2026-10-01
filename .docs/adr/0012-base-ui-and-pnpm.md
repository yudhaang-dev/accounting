# ADR-0012 — shadcn di atas Base UI (`base-nova`) dan pnpm

Status: Diterima (2026-10-01). Mengubah baris "Komponen UI" pada [ADR-0010](0010-first-party-ui.md) dan paket manajer JavaScript proyek.

## Konteks

Komponen shadcn bawaan starter kit memakai Radix UI (style `new-york`). Belum ada layar SPA, sehingga biaya berpindah primitif paling rendah sekarang, dan sebelum M0-13 membangun layar pertama. Starter kit juga membawa `pnpm-workspace.yaml`, sehingga CLI shadcn memasang dependensi dengan pnpm dan merusak `node_modules` hasil npm.

## Keputusan

- Komponen shadcn memakai **Base UI** (`@base-ui/react`) dengan style **`base-nova`**. `components.json` memuat `style: base-nova`; seluruh komponen di `resources/js/components/ui` diganti varian registry `base-nova`. Paket `@radix-ui/*` dihapus.
- **Sonner tetap menjadi toast.** Registry Base UI menyediakan komponen `toast` sendiri, tetapi proyek ini sengaja tetap memakai `sonner`; `ui/sonner.tsx` tidak diubah.
- **pnpm menjadi package manager** (`packageManager` di `package.json`, `pnpm-lock.yaml`); `package-lock.json` dihapus. CI mengaktifkannya lewat `corepack enable`.
- Helper kelas `cn` berasal dari paket `cn` (`resources/js/lib/utils.ts` hanya mengekspor ulang), sesuai registry `base-nova`; `clsx` dan `tailwind-merge` tidak lagi dipakai langsung.
- Untuk custom trigger, pakai prop `render` (bukan `asChild`) pada komponen Base UI.

## Konsekuensi

Tema, font (Geist), dan variabel CSS di `resources/css/app.css` mengikuti preset `nova`. Perilaku beberapa primitif berbeda dari Radix (lihat `.migration/project.md`). Menambah komponen memakai `pnpm dlx shadcn@latest add <nama>`.

## Alternatif ditolak

Tetap di Radix: harus dimigrasi kelak setelah banyak layar bergantung pada `asChild`. Memakai `toast` Base UI menggantikan Sonner: ditolak pemilik produk.
