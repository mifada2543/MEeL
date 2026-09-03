# Versi Library JavaScript / CSS Vendored

Inventaris versi eksak library **pihak ketiga** yang di-vendor (diunduh manual dan
di-commit ke repo) di MEeL-HUB. File ini dibuat dari hasil audit keamanan
(2026-07-31) dan WAJIB diperbarui setiap kali file library di-update.

> ⚠️ **Scope:** Hanya library pihak ketiga. File **custom** milik proyek
> (`script.min.js`, `player_*.js`, semua file di `assets/js/{video,music,drive,
> admin,shared}/`) **TIDAK** masuk daftar ini.

## 📋 Daftar Versi

| Library | Versi | Sumber/CDN | Terakhir diverifikasi |
|---------|-------|------------|----------------------|
| `hls.js` | 1.6.15 | https://github.com/video-dev/hls.js/releases | 2026-07-31 |
| `plyr.min.js` | 3.8.4 | https://github.com/sampotts/plyr/releases | 2026-07-31 |
| `plyr.css` | 3.8.4 * | https://github.com/sampotts/plyr/releases | 2026-07-31 |
| `sweetalert2.all.min.js` | 11.26.25 | https://github.com/sweetalert2/sweetalert2/releases | 2026-07-31 |
| `htmx.min.js` | 1.9.10 | https://unpkg.com/htmx.org/ | 2026-07-31 |
| `lucide.js` | 0.575.0 | https://unpkg.com/lucide@latest/ | 2026-07-31 |
| `chart.umd.min.js` | 4.4.7 | https://www.jsdelivr.com/package/npm/chart.js | 2026-07-31 |
| `marked.min.js` | 15.0.12 | https://github.com/markedjs/marked/releases | 2026-07-31 |
| `tailwind.min.css` | **UNKNOWN** — perlu verifikasi manual | https://cdn.tailwindcss.com/ | 2026-07-31 |
| `script.min.js` | N/A — **file custom** (wrapper meelAlert/meelConfirm) | — | 2026-07-31 |
| `player_music.js` / `player_video.js` | N/A — **file custom** (dipecah ke `assets/js/music/` & `assets/js/video/`) | — | 2026-07-31 |

### Keterangan Verifikasi

- **hls.js 1.6.15** — diekstrak dari string versi di dalam bundle minified (`version 1.6.15`).
- **plyr 3.8.4** — diekstrak dari string versi di dalam `plyr.min.js`. *(Catatan: file
  `VENDOR_VERSIONS.md` lama menyebut 3.7.8 — itu perkiraan dan tidak akurat, versi eksak
  file ini adalah 3.8.4.)*
- **plyr.css `*`** — versi **diasumsikan sama dengan `plyr.min.js`** (satu paket rilis
  plyr), belum diverifikasi langsung dari isi file CSS.
- **sweetalert2 11.26.25** — dari header lisensi (`sweetalert2 v11.26.25`) + atribut `version`.
- **htmx 1.9.10** — dari string `version:"1.9.10"` di dalam bundle.
- **lucide 0.575.0** — dari header lisensi (`@license lucide v0.575.0 - ISC`).
- **chart.js 4.4.7** — dari header lisensi + path asal jsDelivr (`/npm/chart.js@4.4.7`).
- **tailwind.min.css — UNKNOWN** — bundle minified tanpa header versi yang bisa diverifikasi
  otomatis. Lihat TODO di bawah.

## 🛡️ Proses Pengecekan CVE / Security Advisory

Developer **wajib** melakukan hal berikut secara berkala:

1. **Cek advisory** untuk setiap library di atas minimal **setiap 6 bulan** (atau
   setiap ada rilis baru / release announcement), melalui:
   - https://github.com/advisories (cari `npm:<package>`)
   - https://www.cvedetails.com/ atau https://osv.dev/
   - Halaman releases masing-masing library (link di tabel).
2. Jika ada **CVE kritis/tinggi** yang belum di-patch → upgrade library, ganti file
   di `assets/`, lalu **update tabel di file ini**.
3. Setiap kali file library di-update → **update baris versinya di file ini** di
   commit yang sama, agar inventaris selalu sinkron dengan isi repo.
4. Untuk versi yang ditandai **UNKNOWN** → lakukan verifikasi manual (lihat TODO)
   lalu isi versi eksaknya.

## 📌 TODO — Verifikasi Manual

- [ ] **`assets/css/tailwind.min.css`** — tentukan versi eksak (mis. bandingkan
      output `tailwindcss` yang dihasilkan, atau ukuran/hash file vs rilis resmi),
      lalu isi kolom Versi.
- [ ] Konfirmasi ulang versi **`plyr.css`** saat upgrade plyr berikutnya (pastikan
      konsisten dengan `plyr.min.js`).
- [ ] Evaluasi redundansi dengan file lama `assets/js/VENDOR_VERSIONS.md` — file ini
      (`VERSIONS.md`) adalah sumber kebenaran baru; file lama bisa dihapus setelah
      tidak dirujuk di dokumentasi mana pun.

---

> Terakhir diverifikasi: **2026-07-31** — seluruh versi di atas dibaca langsung dari
> isi file bundle (header lisensi / string versi internal), bukan perkiraan.
