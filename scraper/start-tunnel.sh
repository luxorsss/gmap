#!/bin/bash
# ==============================================================================
# MapLeads Pro - Local Scraper Worker & Cloudflare Tunnel Launcher
# ==============================================================================
# Script ini untuk dijalankan di laptop saat ingin melakukan scraping dari cPanel.
# ==============================================================================

PORT=3123
SECRET_TOKEN="${SCRAPER_SECRET:-mapleads_rahasia123}"

echo "=========================================================="
echo "🚀 Memulai MapLeads Pro Scraper Worker di Laptop"
echo "=========================================================="
echo "Port: $PORT"
echo "Token: $SECRET_TOKEN"
echo ""

# Periksa apakah node terpasang
if ! command -v node &> /dev/null; then
    echo "❌ Node.js tidak ditemukan! Silakan pasang Node.js di laptop."
    exit 1
fi

# Jalankan worker di background
export PORT=$PORT
export SCRAPER_SECRET=$SECRET_TOKEN
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

node "$DIR/server.js" &
WORKER_PID=$!

trap "echo ''; echo '🛑 Menghentikan worker (PID $WORKER_PID)...'; kill $WORKER_PID 2>/dev/null; exit 0" INT TERM EXIT

sleep 1

# Cek apakah cloudflared terpasang
if command -v cloudflared &> /dev/null; then
    echo "🌐 Membuka Cloudflare Tunnel publik..."
    echo "👉 Salin URL HTTPS yang muncul di bawah (misal: https://xxxx.trycloudflare.com)"
    echo "👉 Masukkan ke menu 'Pengaturan' di cPanel Anda."
    echo ""
    cloudflared tunnel --url "http://localhost:$PORT"
elif command -v ngrok &> /dev/null; then
    echo "🌐 Membuka Ngrok Tunnel publik..."
    echo "👉 Salin URL HTTPS yang muncul di bawah (misal: https://xxxx.ngrok-free.app)"
    echo "👉 Masukkan ke menu 'Pengaturan' di cPanel Anda."
    echo ""
    ngrok http $PORT
else
    echo "ℹ️  cloudflared atau ngrok tidak ditemukan."
    echo "Worker sudah berjalan secara lokal di: http://localhost:$PORT"
    echo ""
    echo "Untuk menghubungkan ke cPanel, Anda bisa memasang cloudflared:"
    echo "  curl -L --output cloudflared https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64"
    echo "  chmod +x cloudflared && sudo mv cloudflared /usr/local/bin/"
    echo "Lalu jalankan ulang script ini."
    echo ""
    wait $WORKER_PID
fi
