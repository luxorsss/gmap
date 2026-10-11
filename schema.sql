-- Database Schema untuk GMaps Leads Scraper & WhatsApp Outreach
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(20) DEFAULT '#10b981',
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    place_id VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(150) DEFAULT NULL,
    group_id INT DEFAULT NULL,
    address TEXT DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    phone_raw VARCHAR(50) DEFAULT NULL,
    phone_wa VARCHAR(30) NOT NULL UNIQUE,
    rating DECIMAL(2,1) DEFAULT 0.0,
    user_ratings_total INT DEFAULT 0,
    status ENUM('belum_dihubungi', 'dihubungi', 'deal', 'batal') DEFAULT 'belum_dihubungi',
    last_step_sent TINYINT NOT NULL DEFAULT 0,
    step_1_sent_at DATETIME DEFAULT NULL,
    step_2_sent_at DATETIME DEFAULT NULL,
    step_3_sent_at DATETIME DEFAULT NULL,
    fu_1_sent_at DATETIME DEFAULT NULL,
    fu_2_sent_at DATETIME DEFAULT NULL,
    next_followup_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    last_contacted_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_phone_wa (phone_wa),
    INDEX idx_category (category),
    INDEX idx_group_id (group_id),
    INDEX idx_next_fu (status, next_followup_at),
    FOREIGN KEY (group_id) REFERENCES lead_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel Template Pesan WhatsApp Berdasarkan Grup & Langkah
CREATE TABLE IF NOT EXISTS group_message_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NULL,
    step_number TINYINT NOT NULL,
    step_name VARCHAR(50) NOT NULL,
    delay_days INT DEFAULT 0,
    delay_hours INT DEFAULT 0,
    message_text TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_step (group_id, step_number),
    FOREIGN KEY (group_id) REFERENCES lead_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_message_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    step_number INT DEFAULT 1,
    direction ENUM('outgoing', 'incoming') DEFAULT 'outgoing',
    message_text TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data Default Pengaturan
INSERT INTO settings (setting_key, setting_value) VALUES
('google_api_key', ''),
('onesender_api_url', 'http://localhost:3001/api/v1'),
('onesender_token', ''),
('drip_send_start_hour', '8'),
('drip_send_end_hour', '17'),
('batch_delay_seconds', '3')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Default Templates (group_id = NULL)
INSERT IGNORE INTO group_message_templates (group_id, step_number, step_name, delay_days, delay_hours, message_text)
VALUES
(NULL, 1, 'Pesan 1 (Perkenalan)', 0, 0, 'Selamat [waktu] Kak, salam kenal dari kami 🙏\n\nKami melihat profil usaha *[nama_usaha]* di Google Maps. Kebetulan kami sedang ada program khusus untuk pelaku usaha di bidang [kategori] di [kota].\n\nApakah ini dengan pemilik atau pengelola [nama_usaha]?'),
(NULL, 2, 'Pesan 2 (Penawaran Solusi)', 0, 0, 'Terima kasih responnya Kak.\n\nKami membantu usaha seperti *[nama_usaha]* agar lebih mudah mendapatkan pelanggan baru dari internet & Google dengan website profil bisnis yang profesional dan cepat diakses.\n\nBoleh kami kirimkan contoh portofolio dan penawaran singkatnya Kak?'),
(NULL, 3, 'Pesan 3 (Portofolio & Detail)', 0, 0, 'Baik Kak, ini contoh portofolio yang pernah kami kerjakan: https://contoh-portofolio.com\n\nUntuk paket website profil usaha sudah include domain, hosting, serta integrasi tombol chat WhatsApp langsung ke Kakak.\n\nKira-kira jika ada waktu senggang, kapan bisa kami hubungi kembali untuk diskusi singkatnya?'),
(NULL, 4, 'Follow-up 1 (Reminder)', 1, 0, 'Selamat [waktu] Kak, izin mengingatkan kembali terkait penawaran pembuatan website untuk *[nama_usaha]* kemarin.\n\nApakah sudah sempat dilihat contoh portofolionya Kak? Jika ada yang ingin ditanyakan, dengan senang hati kami bantu.'),
(NULL, 5, 'Follow-up 2 (Penawaran Terakhir)', 2, 0, 'Halo Kak, semoga usahanya semakin lancar ya 🙏\n\nSekadar info, promo penawaran khusus pembuatan website untuk *[nama_usaha]* akan berakhir minggu ini.\n\nJika Kakak berminat atau ingin konsultasi gratis dulu, silakan kabari kami ya Kak. Terima kasih!');
