# Panduan Deploy MapLeads Pro ke Shared Hosting cPanel

Panduan ini menjelaskan cara melakukan deploy **MapLeads Pro** ke cPanel dengan arsitektur **Terpisah (Decoupled)**:
- **cPanel (Hosting)**: Web CRM, Database MySQL, OneSender WhatsApp Gateway, Webhook, dan Cron Follow-up 24/7.
- **Laptop / PC Lokal**: Menjalankan engine Puppeteer di project `gmaps-scraper` untuk mencari leads kapan pun Anda butuhkan tanpa membebani hosting dan tanpa Cloudflare Tunnel.

---

## 📦 1. Persiapan File untuk Di-upload ke cPanel

Folder yang **TIDAK PERLU** di-upload ke cPanel (hemat kuota & upload cepat):
- ❌ `node_modules/`
- ❌ `.cache/`
- ❌ `scraper/`

Folder & File yang **WAJIB** di-upload ke `public_html/` (atau subdomain Anda di cPanel):
- ✅ `actions/`
- ✅ `api/` *(Baru: endpoint Inbound Push & Sync Grup)*
- ✅ `config/`
- ✅ `cron/`
- ✅ `logs/`
- ✅ `services/`
- ✅ `views/`
- ✅ `index.php`
- ✅ `search.php`
- ✅ `settings.php`
- ✅ `schema.sql`

---

## 🗄️ 2. Setup Database di cPanel

1. Buka **cPanel** > **MySQL Databases**.
2. Buat database baru (misal: `username_mapleads`).
3. Buat user database baru dan password. Hubungkan user ke database dengan mencentang **ALL PRIVILEGES**.
4. Buka **phpMyAdmin** di cPanel:
   - Pilih database yang baru dibuat.
   - Klik tab **Import**, pilih file `schema.sql`, lalu klik **Go / Kirim**.
5. Sesuaikan file `config/database.php` di cPanel:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'username_mapleads');
   define('DB_USER', 'username_userdb');
   define('DB_PASS', 'password_anda');
   define('DB_PORT', 3306);
   ```

---

## 🔑 3. Konfigurasi API Integrasi di cPanel

1. Buka web CRM Anda di cPanel: `https://crm.domain-anda.com/settings.php`.
2. Scroll ke bagian **🔑 API Integrasi Scraper Desktop / Lokal**.
3. Klik tombol **🎲 Generate Baru** untuk membuat API Key.
4. Klik **Simpan Pengaturan**.
5. Salin API Key tersebut untuk dimasukkan ke aplikasi Scraper Lokal Anda.

---

## ⏰ 4. Setup Cron Job Follow-up di cPanel (24/7 Otomatis)

1. Buka **cPanel** > menu **Cron Jobs**.
2. Pada bagian *Common Settings*, pilih: **Once Per 5 Minutes** (`*/5 * * * *`).
3. Di kolom Command, masukkan perintah:
   ```bash
   /usr/bin/curl -s "https://crm.domain-anda.com/cron/followup_scheduler.php" > /dev/null 2>&1
   ```

---

## 💻 5. Menjalankan Scraper di Laptop (Project `gmaps-scraper`)

1. Buka terminal di laptop:
   ```bash
   cd /srv/http/gmaps-scraper
   npm start
   ```
2. Buka browser di `http://localhost:3000`.
3. Klik **⚙️ Koneksi cPanel**:
   - Masukkan URL cPanel Anda (`https://crm.domain-anda.com`).
   - Masukkan **CRM API Key** yang sudah di-generate sebelumnya.
4. Cari leads sesuai kategori & kota target Anda.
5. Selesai! Data leads otomatis tersimpan rapi di database cPanel Anda dan siap di-follow up via WhatsApp.
