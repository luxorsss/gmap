const { join } = require('path');

/**
 * Konfigurasi Cache Puppeteer Lokal Proyek
 * @type {import("puppeteer").Configuration}
 */
module.exports = {
    cacheDirectory: join(__dirname, '.cache', 'puppeteer'),
};
