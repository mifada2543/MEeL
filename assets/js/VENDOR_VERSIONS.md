# Vendor JavaScript Versions

Berikut adalah daftar versi library JavaScript yang digunakan di MEeL-HUB,
beserta sumber unduhan untuk memudahkan pengecekan CVE dan pembaruan.

| File | Versi | Sumber |
|------|-------|--------|
| `tailwind.min.css` | 3.4.x | https://cdn.tailwindcss.com/ |
| `plyr.min.js` | 3.7.8 | https://github.com/sampotts/plyr/releases |
| `plyr.css` | 3.7.8 | https://github.com/sampotts/plyr/releases |
| `htmx.min.js` | 2.0.x | https://unpkg.com/htmx.org/ |
| `hls.js` | 1.5.x | https://github.com/video-dev/hls.js/releases |
| `lucide.js` | 0.468.x | https://unpkg.com/lucide@latest/ |
| `sweetalert2.all.min.js` | 11.x | https://github.com/sweetalert2/sweetalert2/releases |
| `chart.umd.min.js` | 4.x | https://www.jsdelivr.com/package/npm/chart.js |
| `marked.min.js` | 14.x | https://github.com/markedjs/marked/releases |

> **Catatan**: Versi di atas adalah perkiraan berdasarkan tanggal file.
> Untuk versi eksak, periksa masing-masing file atau gunakan `npm view <package> version`.

## Cara Update

1. Download versi terbaru dari sumber di atas
2. Ganti file di `assets/js/` atau `assets/css/`
3. Update versi di tabel ini
4. Jalankan ulang test suite untuk cek regresi

## CVE Monitoring

- Kunjungi https://www.cvedetails.com/ atau https://github.com/advisories
- Cari library berdasarkan nama dan versi
- Jadwalkan pengecekan setiap bulan
- Jika tidak menggunakan npm, alternatif: `npm audit --json` setelah inisialisasi package.json
