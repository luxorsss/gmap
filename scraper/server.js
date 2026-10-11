const http = require('http');
const url = require('url');
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const PORT = 3123;

function getChromeExecutablePath() {
    if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) {
        return process.env.PUPPETEER_EXECUTABLE_PATH;
    }

    const searchDirs = [
        path.join(__dirname, '../.cache/puppeteer/chrome'),
        '/srv/http/gmaps-leads/.cache/puppeteer/chrome',
        '/home/fadil/.cache/puppeteer/chrome',
        '/srv/http/.cache/puppeteer/chrome'
    ];

    for (const dir of searchDirs) {
        if (fs.existsSync(dir)) {
            try {
                const subdirs = fs.readdirSync(dir);
                for (const sub of subdirs) {
                    const target = path.join(dir, sub, 'chrome-linux64', 'chrome');
                    if (fs.existsSync(target)) return target;
                }
            } catch (e) {}
        }
    }

    const systemBinaries = [
        '/opt/google/chrome/chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser'
    ];
    for (const bin of systemBinaries) {
        if (fs.existsSync(bin)) return bin;
    }

    return undefined;
}

/**
 * Normalisasi nomor HP ke format WA Indonesia (628xxxxxxxx)
 */
function normalizePhone(raw) {
    if (!raw) return null;
    let clean = raw.replace(/[^0-9+]/g, '').trim();
    if (clean.startsWith('+62')) {
        clean = clean.substring(1);
    } else if (clean.startsWith('08')) {
        clean = '628' + clean.substring(2);
    } else if (clean.startsWith('628')) {
        // ok
    } else {
        return null;
    }
    if (/^628[0-9]{8,12}$/.test(clean)) {
        return clean;
    }
    return null;
}

/**
 * Scraper dengan dukungan streaming callback
 */
async function scrapeGoogleMaps(query, maxTarget = 20, onProgress = null) {
    let browser;
    let tempUserDataDir = path.join('/tmp', 'pptr_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7));
    try {
        const execPath = getChromeExecutablePath();
        const launchOptions = {
            headless: 'new',
            userDataDir: tempUserDataDir,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--no-zygote',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-crash-reporter',
                '--disable-breakpad',
                '--crash-dumps-dir=/tmp',
                '--no-first-run',
                '--lang=id-ID,id'
            ]
        };

        if (execPath) {
            launchOptions.executablePath = execPath;
        }

        if (onProgress) onProgress({ type: 'progress', status: 'Membuka browser Google Maps...', scanned: 0, found: 0 });

        browser = await puppeteer.launch(launchOptions);
        const page = await browser.newPage();
        await page.setUserAgent(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
        );
        await page.setViewport({ width: 1280, height: 800 });

        const searchUrl = `https://www.google.com/maps/search/${encodeURIComponent(query)}?hl=id`;
        await page.goto(searchUrl, { waitUntil: 'networkidle2', timeout: 60000 });

        const feedSelector = 'div[role="feed"]';
        try {
            await page.waitForSelector(feedSelector, { timeout: 15000 });
        } catch (e) {}

        if (onProgress) onProgress({ type: 'progress', status: 'Mulai memindai dan men-scroll Google Maps...', scanned: 0, found: 0 });

        const results = [];
        const seenUrls = new Set();
        const seenPhones = new Set(); // HANYA DEDUPLIKASI NOMOR WA!
        let scannedCount = 0;
        let consecutiveNoNewCards = 0;
        let hasReachedEnd = false;

        // Loop utama: Terus periksa dan scroll ke bawah hingga target tercapai atau data habis
        while (results.length < maxTarget && consecutiveNoNewCards < 8 && !hasReachedEnd) {
            // Ambil semua elemen link tempat yang ada di DOM saat ini
            const currentLinks = await page.$$('a[href*="/maps/place/"]');
            let processedInThisBatch = 0;

            for (let i = 0; i < currentLinks.length; i++) {
                if (results.length >= maxTarget) break;

                try {
                    const freshLinks = await page.$$('a[href*="/maps/place/"]');
                    if (!freshLinks[i]) continue;
                    const link = freshLinks[i];

                    const href = await page.evaluate(el => el.href, link);
                    const ariaName = await page.evaluate(el => el.getAttribute('aria-label') || '', link);

                    if (!href || seenUrls.has(href)) continue;
                    seenUrls.add(href);
                    processedInThisBatch++;

                    scannedCount++;
                    if (onProgress) {
                        onProgress({
                            type: 'progress',
                            status: `Memeriksa profil ${scannedCount} (Target: ${results.length}/${maxTarget}): ${ariaName || 'Tempat'}...`,
                            scanned: scannedCount,
                            found: results.length
                        });
                    }

                    // Klik item untuk memunculkan detail di panel kiri
                    await link.click();

                    const cleanAria = (ariaName || '').trim().toLowerCase();
                    try {
                        await page.waitForFunction(
                            (expected) => {
                                const h1 = document.querySelector('h1.DUwDvf') || document.querySelector('h1');
                                if (!h1) return false;
                                const text = h1.textContent.trim().toLowerCase();
                                return text.length > 0 && (!expected || text.includes(expected.substring(0, 10).toLowerCase()));
                            },
                            { timeout: 3200 },
                            cleanAria
                        );
                    } catch (e) {
                        await new Promise(r => setTimeout(r, 1200));
                    }

                    // Ekstrak data dari panel
                    const placeData = await page.evaluate((placeUrl, fallbackName) => {
                        const cleanGlyphs = (str) => {
                            if (!str) return '';
                            return str.replace(/[\uE000-\uF8FF]/g, '').trim();
                        };

                        const nameEl = document.querySelector('h1.DUwDvf') || document.querySelector('h1');
                        let name = nameEl ? cleanGlyphs(nameEl.textContent) : '';
                        if (!name && fallbackName) {
                            name = cleanGlyphs(fallbackName);
                        }

                        if (!name || name.toLowerCase() === 'hasil' || name.toLowerCase().startsWith('hasil untuk')) {
                            return null;
                        }

                        const catEl = document.querySelector('button[jsaction*="pane.rating.category"]') || 
                                      document.querySelector('button.DkEaL') ||
                                      document.querySelector('div.fontBodyMedium');
                        const category = catEl ? cleanGlyphs(catEl.textContent) : 'Bisnis Lokal';

                        let rating = 0.0;
                        let reviews = 0;
                        const ratingEl = document.querySelector('div.fontDisplayLarge') || document.querySelector('span.ceNzKf');
                        if (ratingEl) {
                            const rawR = cleanGlyphs(ratingEl.textContent);
                            rating = parseFloat(rawR.replace(',', '.')) || 0.0;
                        }

                        const reviewsEl = document.querySelector('span[aria-label*="ulasan"], span[aria-label*="reviews"]');
                        if (reviewsEl) {
                            const aria = reviewsEl.getAttribute('aria-label') || '';
                            const m = aria.match(/([0-9.,]+)/);
                            if (m) {
                                reviews = parseInt(m[1].replace(/[^0-9]/g, ''), 10) || 0;
                            }
                        }

                        const webEl = document.querySelector('a[data-item-id="authority"]') || 
                                      document.querySelector('a[aria-label*="Situs web" i]') ||
                                      document.querySelector('a[aria-label*="Website" i]');
                        const website = webEl ? (webEl.href || webEl.getAttribute('aria-label') || '').trim() : '';

                        const phoneEl = document.querySelector('button[data-item-id*="phone:tel:"]') || 
                                        document.querySelector('button[aria-label*="Telepon:" i]') ||
                                        document.querySelector('button[aria-label*="Phone:" i]');
                        let phone = '';
                        if (phoneEl) {
                            phone = cleanGlyphs(phoneEl.textContent || phoneEl.getAttribute('aria-label') || '');
                            phone = phone.replace(/^Telepon:\s*/i, '').replace(/^Phone:\s*/i, '').trim();
                        }

                        const addrEl = document.querySelector('button[data-item-id="address"]') || 
                                       document.querySelector('button[aria-label*="Alamat:" i]');
                        let address = '';
                        if (addrEl) {
                            address = cleanGlyphs(addrEl.textContent || addrEl.getAttribute('aria-label') || '');
                            address = address.replace(/^Alamat:\s*/i, '').trim();
                        }

                        let placeId = placeUrl;
                        const matchId = placeUrl.match(/!1s(0x[0-9a-f]+:[0-9a-f]+)/i) || placeUrl.match(/\/place\/([^\/]+)/);
                        if (matchId) {
                            placeId = matchId[1];
                        }

                        return {
                            place_id: placeId,
                            name: name,
                            category: category,
                            address: address,
                            phone: phone,
                            website: website,
                            rating: rating,
                            user_ratings_total: reviews
                        };
                    }, href, ariaName);

                    if (placeData && placeData.name) {
                        const normPhone = normalizePhone(placeData.phone);

                        // DEDUPLIKASI HANYA BERDASARKAN NOMOR WHATSAPP!
                        if (normPhone && seenPhones.has(normPhone)) {
                            continue;
                        }
                        if (normPhone) {
                            seenPhones.add(normPhone);
                        }

                        results.push(placeData);

                        if (onProgress) {
                            onProgress({
                                type: 'item',
                                place: placeData,
                                scanned: scannedCount,
                                found: results.length
                            });
                        }
                    }
                } catch (err) {}
            }

            if (results.length >= maxTarget) break;

            // Periksa apakah Google Maps sudah menyatakan habis ("Anda telah mencapai akhir daftar")
            const reachedEnd = await page.evaluate(() => {
                const el = document.querySelector('span.HlvSq, div.HlvSq');
                if (el) return true;

                const texts = Array.from(document.querySelectorAll('p.fontBodyMedium, span.fontBodyMedium'));
                return texts.some(t => /akhir daftar|end of the list/i.test(t.textContent || ''));
            });

            if (reachedEnd) {
                hasReachedEnd = true;
                break;
            }

            // Scroll ke bawah container feed untuk memuat bisnis berikutnya
            if (onProgress) {
                onProgress({
                    type: 'progress',
                    status: `Men-scroll Google Maps lebih dalam untuk mencari lebih banyak tempat... (Dapat: ${results.length}/${maxTarget})`,
                    scanned: scannedCount,
                    found: results.length
                });
            }

            const beforeScrollCount = (await page.$$('a[href*="/maps/place/"]')).length;

            await page.evaluate((sel) => {
                const feed = document.querySelector(sel);
                if (feed) {
                    feed.scrollTop = feed.scrollHeight;
                } else {
                    window.scrollBy(0, 1500);
                }
            }, feedSelector);

            await new Promise(r => setTimeout(r, 1600));

            const afterScrollCount = (await page.$$('a[href*="/maps/place/"]')).length;
            if (afterScrollCount <= beforeScrollCount && processedInThisBatch === 0) {
                consecutiveNoNewCards++;
                // Coba scroll lagi dengan sedikit getar/dorongan
                await page.evaluate((sel) => {
                    const feed = document.querySelector(sel);
                    if (feed) {
                        feed.scrollTop = feed.scrollHeight - 200;
                        setTimeout(() => { feed.scrollTop = feed.scrollHeight; }, 300);
                    }
                }, feedSelector);
                await new Promise(r => setTimeout(r, 1200));
            } else {
                consecutiveNoNewCards = 0;
            }
        } // akhir while loop

        if (onProgress) {
            onProgress({
                type: 'done',
                status: 'Selesai!',
                scanned: scannedCount,
                found: results.length,
                places: results
            });
        }

        return { success: true, places: results };
    } catch (err) {
        if (onProgress) onProgress({ type: 'error', error: err.message });
        return { success: false, error: err.message };
    } finally {
        if (browser) {
            try { await browser.close(); } catch (e) {}
        }
        if (tempUserDataDir && fs.existsSync(tempUserDataDir)) {
            try { fs.rmSync(tempUserDataDir, { recursive: true, force: true }); } catch (e) {}
        }
    }
}

const PORT = process.env.PORT || 3123;
const SCRAPER_SECRET = process.env.SCRAPER_SECRET || '';

function verifySecret(req, parsedUrl) {
    if (!SCRAPER_SECRET) return true; // Tidak ada password diset, izinkan
    const authHeader = req.headers['x-scraper-token'] || req.headers['authorization'] || '';
    const tokenQuery = parsedUrl.query.token || '';
    if (tokenQuery === SCRAPER_SECRET) return true;
    if (authHeader === SCRAPER_SECRET || authHeader === `Bearer ${SCRAPER_SECRET}`) return true;
    return false;
}

// HTTP Server
const server = http.createServer(async (req, res) => {
    const parsedUrl = url.parse(req.url, true);

    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Scraper-Token, Authorization');

    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        res.end();
        return;
    }

    if (parsedUrl.pathname === '/status' || parsedUrl.pathname === '/ping') {
        const authed = verifySecret(req, parsedUrl);
        res.setHeader('Content-Type', 'application/json; charset=utf-8');
        res.writeHead(200);
        res.end(JSON.stringify({ 
            status: 'running', 
            port: PORT, 
            auth_required: Boolean(SCRAPER_SECRET),
            authenticated: authed
        }));
        return;
    }

    // Cek autentikasi token untuk endpoint scraping
    if (!verifySecret(req, parsedUrl)) {
        res.setHeader('Content-Type', 'application/json; charset=utf-8');
        res.writeHead(401);
        res.end(JSON.stringify({ success: false, error: 'Unauthorized: Token Scraper tidak valid' }));
        return;
    }

    // Endpoint Streaming Real-time SSE (Anti-Timeout 504!)
    if (parsedUrl.pathname === '/stream') {
        const query = parsedUrl.query.query || '';
        const max = parseInt(parsedUrl.query.max || '20', 10);

        if (!query) {
            res.setHeader('Content-Type', 'application/json; charset=utf-8');
            res.writeHead(400);
            res.end(JSON.stringify({ success: false, error: 'Query parameter required' }));
            return;
        }

        res.writeHead(200, {
            'Content-Type': 'text/event-stream',
            'Cache-Control': 'no-cache, no-transform',
            'Connection': 'keep-alive',
            'X-Accel-Buffering': 'no'
        });

        // Kirim keep-alive awal
        res.write(': keepalive\n\n');

        await scrapeGoogleMaps(query, max, (evt) => {
            res.write(`data: ${JSON.stringify(evt)}\n\n`);
        });

        res.end();
        return;
    }

    // Endpoint Reguler
    if (parsedUrl.pathname === '/scrape') {
        res.setHeader('Content-Type', 'application/json; charset=utf-8');
        const query = parsedUrl.query.query || '';
        const max = parseInt(parsedUrl.query.max || '20', 10);

        if (!query) {
            res.writeHead(400);
            res.end(JSON.stringify({ success: false, error: 'Query parameter required' }));
            return;
        }

        const data = await scrapeGoogleMaps(query, max);
        res.writeHead(200);
        res.end(JSON.stringify(data));
        return;
    }

    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    res.writeHead(404);
    res.end(JSON.stringify({ error: 'Not Found' }));
});

const HOST = '0.0.0.0';
server.listen(PORT, HOST, () => {
    console.log(`Google Maps Scraper Worker running at http://${HOST}:${PORT}`);
    if (SCRAPER_SECRET) {
        console.log(`Protected with SCRAPER_SECRET token.`);
    } else {
        console.log(`No token configured. Open access.`);
    }
});
