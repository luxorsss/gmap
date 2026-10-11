const puppeteer = require('puppeteer');

// Parse argument
const query = process.argv[2] || 'bengkel mobil di bandung';
const maxTarget = parseInt(process.argv[3] || '15', 10);

const fs = require('fs');
const path = require('path');

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

(async () => {
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

        browser = await puppeteer.launch(launchOptions);

        const page = await browser.newPage();
        await page.setUserAgent(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
        );

        await page.setViewport({ width: 1280, height: 800 });

        const searchUrl = `https://www.google.com/maps/search/${encodeURIComponent(query)}?hl=id`;
        await page.goto(searchUrl, { waitUntil: 'networkidle2', timeout: 60000 });

        // Tunggu feed hasil pencarian
        const feedSelector = 'div[role="feed"]';
        try {
            await page.waitForSelector(feedSelector, { timeout: 15000 });
        } catch (e) {}

        // Scroll feed untuk memuat lebih banyak bisnis
        let prevCount = 0;
        let scrolls = 0;
        while (scrolls < 5) {
            const items = await page.$$('a[href*="/maps/place/"]');
            if (items.length >= maxTarget + 5 || (items.length === prevCount && scrolls > 2)) {
                break;
            }
            prevCount = items.length;

            await page.evaluate((sel) => {
                const feed = document.querySelector(sel);
                if (feed) {
                    feed.scrollTop = feed.scrollHeight;
                } else {
                    window.scrollBy(0, 1000);
                }
            }, feedSelector);

            await new Promise(r => setTimeout(r, 1500));
            scrolls++;
        }

        // Ambil elemen link listing
        const placeLinks = await page.$$('a[href*="/maps/place/"]');
        const results = [];
        const seenUrls = new Set();

        for (let i = 0; i < placeLinks.length; i++) {
            if (results.length >= maxTarget) break;

            try {
                const currentLinks = await page.$$('a[href*="/maps/place/"]');
                if (!currentLinks[i]) continue;

                const link = currentLinks[i];
                const href = await page.evaluate(el => el.href, link);
                const ariaName = await page.evaluate(el => el.getAttribute('aria-label') || '', link);
                if (seenUrls.has(href)) continue;
                seenUrls.add(href);

                // Klik item untuk memunculkan panel detail di sisi kiri
                await link.click();
                await new Promise(r => setTimeout(r, 2200));

                // Ekstrak informasi dari panel detail
                const placeData = await page.evaluate((placeUrl, fallbackName) => {
                    const cleanGlyphs = (str) => {
                        if (!str) return '';
                        // Hapus Unicode Private Use Area characters (ikon Maps seperti , )
                        return str.replace(/[\uE000-\uF8FF]/g, '').trim();
                    };

                    // Nama
                    const nameEl = document.querySelector('h1.DUwDvf') || document.querySelector('h1');
                    let name = nameEl ? cleanGlyphs(nameEl.textContent) : '';
                    if (!name && fallbackName) {
                        name = cleanGlyphs(fallbackName);
                    }

                    // Lewati header atau tombol "Hasil"
                    if (!name || name.toLowerCase() === 'hasil' || name.toLowerCase().startsWith('hasil untuk')) {
                        return null;
                    }

                    // Kategori
                    const catEl = document.querySelector('button[jsaction*="pane.rating.category"]') || 
                                  document.querySelector('button.DkEaL') ||
                                  document.querySelector('div.fontBodyMedium');
                    const category = catEl ? cleanGlyphs(catEl.textContent) : 'Bisnis Lokal';

                    // Rating & Ulasan
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

                    // Website
                    const webEl = document.querySelector('a[data-item-id="authority"]') || 
                                  document.querySelector('a[aria-label*="Situs web" i]') ||
                                  document.querySelector('a[aria-label*="Website" i]');
                    const website = webEl ? (webEl.href || webEl.getAttribute('aria-label') || '').trim() : '';

                    // Telepon
                    const phoneEl = document.querySelector('button[data-item-id*="phone:tel:"]') || 
                                    document.querySelector('button[aria-label*="Telepon:" i]') ||
                                    document.querySelector('button[aria-label*="Phone:" i]');
                    let phone = '';
                    if (phoneEl) {
                        phone = cleanGlyphs(phoneEl.textContent || phoneEl.getAttribute('aria-label') || '');
                        phone = phone.replace(/^Telepon:\s*/i, '').replace(/^Phone:\s*/i, '').trim();
                    }

                    // Alamat
                    const addrEl = document.querySelector('button[data-item-id="address"]') || 
                                   document.querySelector('button[aria-label*="Alamat:" i]');
                    let address = '';
                    if (addrEl) {
                        address = cleanGlyphs(addrEl.textContent || addrEl.getAttribute('aria-label') || '');
                        address = address.replace(/^Alamat:\s*/i, '').trim();
                    }

                    // Place ID dari URL
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
                    results.push(placeData);
                }
            } catch (err) {}
        }

        console.log('__GMAPS_RESULT_START__');
        console.log(JSON.stringify(results));
        console.log('__GMAPS_RESULT_END__');
    } catch (err) {
        console.error('__GMAPS_ERROR_START__');
        console.error(err.message);
        console.error('__GMAPS_ERROR_END__');
        process.exit(1);
    } finally {
        if (browser) {
            try {
                await browser.close();
            } catch (e) {}
        }
        if (tempUserDataDir && fs.existsSync(tempUserDataDir)) {
            try {
                fs.rmSync(tempUserDataDir, { recursive: true, force: true });
            } catch (e) {}
        }
    }
})();
