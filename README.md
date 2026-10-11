# MapLeads Pro 🚀
**Google Maps Lead Scraper & WhatsApp Outreach Mini-CRM**

Aplikasi PHP Native & MySQL untuk mencari prospek bisnis lokal di Google Maps yang **belum memiliki website**, memfilter hanya yang memiliki nomor WhatsApp aktif, dan memfasilitasi penawaran jasa pembuatan website dengan template pesan personalisasi otomatis.

---

## 🛠️ Fitur Utama

1. **Scraper Otomatis Mandiri (100% GRATIS - Tanpa Kartu Kredit & Tanpa API Key)**:
   - Menggunakan Puppeteer headless browser di latar belakang.
   - Tidak perlu mendaftar Google Cloud, tidak perlu kartu kredit/debit, dan tidak perlu API Key.
   - Cukup ketik kategori usaha dan kota, sistem langsung membuka Google Maps dan menyaring prospek.
2. **Dukungan Google Places API Resmi (Opsional)**:
   - Jika Anda memiliki API Key Google Cloud, opsi ini tetap tersedia sebagai alternatif.
2. **Filter Cerdas**:
   - **Hanya Tanpa Website**: Memfilter keluar bisnis yang sudah memiliki website resmi. Tersedia opsi menyertakan bisnis yang hanya mencantumkan link medsos (Instagram/Facebook/Linktree) sebagai prospek yang belum punya website.
   - **Hanya Nomor WhatsApp (08 / +628)**: Otomatis membuang nomor telepon kabel (seperti 021, 031) dan menormalisasi nomor seluler ke format internasional (`628xxxxxxxx`).
   - **Anti-Duplikasi Database**: Menggunakan `place_id` unik dari Google Maps agar prospek yang sama tidak tersimpan dobel.
3. **Mini-CRM & Manajemen Prospek**:
   - Status prospek sederhana: `Belum dihubungi`, `Dihubungi`, `Deal`, `Batal`.
   - Update status instan (AJAX tanpa refresh halaman).
   - Pengelompokan grup prospek (Folder/Tags) dengan template pesan khusus per grup.
   - Catatan / Notes riwayat per prospek.
4. **WhatsApp Gateway (OneSender) & Otomasi Drip 5 Langkah**:
   - Integrasi langsung via REST API OneSender (`POST /api/v1/messages`).
   - Placeholder waktu pintar `[waktu]`: otomatis berubah menjadi *pagi*, *siang*, *sore*, atau *malam* sesuai jam pengiriman WIB.
   - Placeholder data: `[nama_usaha]`, `[kategori]`, `[alamat]`, `[kota]`, `[rating]`.
   - **Pesan 1**: Tombol kirim massal per grup atau satuan (otomatis mengubah status ke `Dihubungi`).
   - **Pesan 2 & Pesan 3**: Tombol kirim satuan di baris tabel yang otomatis hilang setelah diklik agar tidak terkirim ganda.
   - **Follow-up 1 & 2 Otomatis**: Berjalan otomatis setelah Pesan 3 terkirim jika status masih `Dihubungi` (batal jika sudah `Deal` atau `Batal`), dengan jeda hari & jam yang dapat disesuaikan per grup.
   - **Batasan Jam Kerja**: Pengiriman pesan follow up dibatasi hanya pada jam kerja (misal 08:00 - 17:00 WIB).
   - **Webhook Receiver**: Endpoint `actions/webhook_onesender.php` untuk mencatat balasan masuk dari prospek ke database log.
5. **Ekspor Data Spreadsheet**:
   - Ekspor data prospek ke format CSV (kompatibel Excel & Google Sheets dengan UTF-8 BOM).

---

## 🚀 Cara Menjalankan Aplikasi

Aplikasi sekarang sudah mendukung penuh akses langsung via Apache di path:
👉 **http://localhost/gmaps-leads**

### Menjalankan Scraper Worker (Latar Belakang):
Untuk memastikan scraping via Apache berjalan lancar tanpa terhalang isolasi systemd, worker Node.js dijalankan di latar belakang:
```bash
node /srv/http/gmaps-leads/scraper/server.js
```
*(Saat ini worker sudah aktif berjalan di latar belakang komputer Anda).*

---

## 🔑 Pengaturan Google Places API (Opsional)

1. Buka menu **⚙️ Pengaturan & Template** di dashboard.
2. Masukkan **Google Places API Key**.
   - Dapatkan API Key di [Google Cloud Console](https://console.cloud.google.com/).
   - Aktifkan library **Places API (New)**.
   - Google memberikan kredit gratis **$200 setiap bulan** untuk setiap akun.
3. Sesuaikan template pesan penawaran dan simpan.

---

## ⏰ Otomasi Antrean Follow-up (Web Cron Job Pihak Ketiga)

Untuk mengotomasi pengiriman pesan follow-up menggunakan layanan cron job pihak ketiga (seperti **cron-job.org**, **EasyCron**, atau cPanel Cron):

1. **URL Cron Job**:
   ```
   https://domain-anda.com/cron/followup_scheduler.php
   ```
   *(Atau di server lokal: `http://localhost/gmaps-leads/cron/followup_scheduler.php`)*

2. **Pengaturan Rekomendasi di Layanan Cron**:
   - **Metode**: `GET`
   - **Jadwal Eksekusi**: Setiap **5** atau **10 menit** (misal: `*/5 * * * *` atau `*/10 * * * *`).
   - **Timeout**: Minimal 30 - 60 detik.

3. **Fitur & Log Scheduler**:
   - **Tampilan Real-time**: Jika URL dibuka di browser, akan menampilkan console log eksekusi yang rapi dan detail antrean berikutnya.
   - **File Log Bulanan**: Tersimpan otomatis di folder `logs/followup_YYYY-MM.log`.
   - **Mode Debug / Force**: Anda dapat menambahkan parameter `?force=1` di URL (misal: `/cron/followup_scheduler.php?force=1`) untuk menguji eksekusi antrean di luar jam kerja.
   - **Trigger Manual**: Tetap dapat dieksekusi instan kapan saja lewat tombol **⚡ Jalankan FU** di dashboard web.
