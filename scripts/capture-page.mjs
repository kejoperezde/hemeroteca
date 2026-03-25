/**
 * capture-page.mjs — Advanced offline page snapshot tool
 *
 * Usage:
 *   node capture-page.mjs <url> <outputDir>
 *
 * Required dependencies:
 *   npm install puppeteer archiver mime
 *
 * Output structure:
 *   outputDir/
 *     page.html              ← HTML con rutas locales relativas
 *     page.inline.html       ← HTML completamente autocontenido (data URIs)
 *     page.mhtml             ← Archivo MHTML (Chrome/Edge lo abre directo)
 *     page.pdf               ← PDF exportado
 *     screenshot-desktop.png ← Screenshot completo (1440px)
 *     screenshot-viewport.png← Solo el viewport
 *     screenshot-mobile.png  ← Vista móvil (390px)
 *     network.har            ← Log de red formato HAR
 *     metadata.json          ← Metadatos detallados
 *     archive.zip            ← Todo empaquetado
 *     assets/
 *       css/   js/   img/   fonts/   data/   other/
 */

import fs from 'node:fs/promises';
import { createWriteStream } from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import puppeteer from 'puppeteer';
import archiver from 'archiver';
import mime from 'mime';

// ─────────────────────────────────────────────
// CLI
// ─────────────────────────────────────────────

const [, , targetUrl, outputDir] = process.argv;

if (!targetUrl || !outputDir) {
    process.stderr.write('Usage: node capture-page.mjs <url> <outputDir>\n');
    process.exit(1);
}

// ─────────────────────────────────────────────
// Utilities
// ─────────────────────────────────────────────

const ensureDir = (dir) => fs.mkdir(dir, { recursive: true });

const writeJson = (filePath, value) =>
    fs.writeFile(filePath, JSON.stringify(value, null, 2), 'utf8');

const log = (msg) => process.stderr.write(`[capture] ${msg}\n`);

const hashUrl = (url) =>
    crypto.createHash('md5').update(url).digest('hex').slice(0, 14);

/** Derive file extension from URL path or MIME type */
const getExtension = (url, mimeType) => {
    try {
        const urlPath = new URL(url).pathname;
        const ext = path.extname(urlPath).toLowerCase();
        if (ext && ext.length >= 2 && ext.length <= 6) return ext;
    } catch { /* ignore */ }
    const cleanMime = (mimeType || '').split(';')[0].trim();
    const ext = mime.getExtension(cleanMime);
    return ext ? `.${ext}` : '';
};

/** Bucket MIME types into asset subfolders */
const assetFolder = (mimeType = '') => {
    if (mimeType.startsWith('image/')) return 'img';
    if (mimeType.startsWith('font/') || mimeType.includes('font')) return 'fonts';
    if (mimeType.includes('css')) return 'css';
    if (mimeType.includes('javascript') || mimeType.includes('ecmascript')) return 'js';
    if (mimeType.includes('json') || mimeType.includes('xml')) return 'data';
    return 'other';
};

// ─────────────────────────────────────────────
// Browser helpers
// ─────────────────────────────────────────────

/**
 * Scroll the page progressively so lazy-loaded content is triggered.
 * Stops when scroll position doesn't advance (infinite-scroll guard).
 */
const autoScroll = async (page) => {
    await page.evaluate(async () => {
        await new Promise((resolve) => {
            const DISTANCE = 400;
            const INTERVAL = 140;
            const MAX_IDLES = 5;
            let idleCount = 0;
            let lastScrollHeight = 0;

            const timer = setInterval(() => {
                window.scrollBy(0, DISTANCE);
                const newHeight = document.body.scrollHeight;
                const atBottom = window.scrollY + window.innerHeight >= newHeight;

                if (atBottom) {
                    if (newHeight === lastScrollHeight) {
                        idleCount++;
                        if (idleCount >= MAX_IDLES) {
                            clearInterval(timer);
                            window.scrollTo(0, 0);
                            resolve();
                            return;
                        }
                    } else {
                        idleCount = 0;
                    }
                    lastScrollHeight = newHeight;
                }
            }, INTERVAL);
        });
    });
};

/** Wait for all <img> to load and for document.fonts to be ready */
const waitForContent = async (page) => {
    await page.evaluate(async () => {
        const imgs = [...document.querySelectorAll('img[src]')];
        await Promise.allSettled(
            imgs.map((img) =>
                img.complete
                    ? Promise.resolve()
                    : new Promise((r) => {
                          img.onload = r;
                          img.onerror = r;
                      }),
            ),
        );
        if (document.fonts?.ready) await document.fonts.ready;
    });
};

const waitForSettledNetwork = async (page, timeout = 12_000) => {
    await page.waitForNetworkIdle({ idleTime: 1_200, timeout }).catch(() => {});
};

// ─────────────────────────────────────────────
// Resource capture via CDP
// ─────────────────────────────────────────────

/**
 * Attach a CDP session to `page` and collect every loaded resource.
 * Returns { client, resourceMap }
 * resourceMap: Map<url, {body:Buffer, mimeType:string, filename:string, status:number}>
 */
const attachResourceCapture = async (page) => {
    const client = await page.createCDPSession();
    await client.send('Network.enable', {
        maxResourceBufferSize: 100 * 1024 * 1024,
        maxTotalBufferSize: 300 * 1024 * 1024,
    });

    const resourceMap = new Map();
    const pendingMeta = new Map();

    client.on('Network.responseReceived', ({ requestId, response }) => {
        const { url, mimeType, status, headers } = response;
        if (url.startsWith('data:') || url.startsWith('blob:')) return;
        if (status < 200 || status >= 400) return;

        const cleanMime = (headers['content-type'] || mimeType || '').split(';')[0].trim();
        pendingMeta.set(requestId, { url, mimeType: cleanMime, status });
    });

    client.on('Network.loadingFinished', async ({ requestId }) => {
        const meta = pendingMeta.get(requestId);
        if (!meta) return;
        pendingMeta.delete(requestId);

        if (resourceMap.has(meta.url)) return;

        try {
            const { body, base64Encoded } = await client.send('Network.getResponseBody', {
                requestId,
            });
            const buffer = base64Encoded
                ? Buffer.from(body, 'base64')
                : Buffer.from(body, 'utf8');

            const ext = getExtension(meta.url, meta.mimeType);
            const filename = `${hashUrl(meta.url)}${ext}`;

            resourceMap.set(meta.url, {
                body: buffer,
                mimeType: meta.mimeType,
                filename,
                status: meta.status,
            });
        } catch {
            // Cached or streaming responses cannot expose body — skip silently
        }
    });

    return { client, resourceMap };
};

// ─────────────────────────────────────────────
// CSS rewriting
// ─────────────────────────────────────────────

/**
 * Rewrite url() tokens inside CSS using a resolver function.
 * resolveUrl(rawToken, cssFileUrl) → replacement string | null
 */
const rewriteCssUrls = (cssText, cssFileUrl, resolveUrl) =>
    cssText.replace(/url\(\s*(['"]?)([^'")\s]+)\1\s*\)/gi, (match, _q, rawUrl) => {
        if (rawUrl.startsWith('data:') || rawUrl.startsWith('#')) return match;
        const replacement = resolveUrl(rawUrl, cssFileUrl);
        return replacement ? `url("${replacement}")` : match;
    });

// ─────────────────────────────────────────────
// Linked HTML builder  (assets saved to disk)
// ─────────────────────────────────────────────

/**
 * Save all captured resources to `assetsDir` and produce an HTML string
 * where every remote URL has been replaced with a local relative path.
 */
const buildLinkedSnapshot = async (rawHtml, resourceMap, assetsDir, assetsRelDir) => {
    const urlToRel = new Map();

    // Pass 1: save files, build url → relPath map
    for (const [url, { body, mimeType, filename }] of resourceMap) {
        const folder = assetFolder(mimeType);
        const dir = path.join(assetsDir, folder);
        await ensureDir(dir);
        await fs.writeFile(path.join(dir, filename), body);
        urlToRel.set(url, `${assetsRelDir}/${folder}/${filename}`);
    }

    // Pass 2: rewrite url() inside saved CSS files
    for (const [url, { mimeType, filename }] of resourceMap) {
        if (!mimeType?.includes('css')) continue;
        const folder = assetFolder(mimeType);
        const filePath = path.join(assetsDir, folder, filename);
        const cssText = await fs.readFile(filePath, 'utf8');
        const rewritten = rewriteCssUrls(cssText, url, (raw, cssUrl) => {
            try {
                return urlToRel.get(new URL(raw, cssUrl).href) ?? null;
            } catch { return null; }
        });
        await fs.writeFile(filePath, rewritten, 'utf8');
    }

    // Pass 3: rewrite HTML — longest URLs first to avoid substring collisions
    let html = rawHtml;
    for (const [url, rel] of [...urlToRel.entries()].sort((a, b) => b[0].length - a[0].length)) {
        html = html.replaceAll(url, rel);
    }

    return html;
};

// ─────────────────────────────────────────────
// Inline single-file HTML builder
// ─────────────────────────────────────────────

/**
 * Produce a single self-contained HTML file where every resource is
 * embedded as a base64 data URI. No external requests needed to view.
 */
const buildInlineSnapshot = (rawHtml, resourceMap) => {
    const urlToDataUri = new Map();

    // Non-CSS first (images, fonts, JS…)
    for (const [url, { body, mimeType }] of resourceMap) {
        if (mimeType?.includes('css')) continue;
        const type = mimeType || 'application/octet-stream';
        urlToDataUri.set(url, `data:${type};base64,${body.toString('base64')}`);
    }

    // CSS: inline their internal url() references first, then encode
    for (const [url, { body, mimeType }] of resourceMap) {
        if (!mimeType?.includes('css')) continue;
        let cssText = body.toString('utf8');
        cssText = rewriteCssUrls(cssText, url, (raw, cssUrl) => {
            try {
                return urlToDataUri.get(new URL(raw, cssUrl).href) ?? null;
            } catch { return null; }
        });
        const encoded = Buffer.from(cssText).toString('base64');
        urlToDataUri.set(url, `data:text/css;base64,${encoded}`);
    }

    // Rewrite HTML — longest URLs first
    let html = rawHtml;
    for (const [url, dataUri] of [...urlToDataUri.entries()].sort((a, b) => b[0].length - a[0].length)) {
        html = html.replaceAll(url, dataUri);
    }

    return html;
};

// ─────────────────────────────────────────────
// ZIP helper
// ─────────────────────────────────────────────

const createZip = (sourceDir, zipPath) =>
    new Promise((resolve, reject) => {
        const output = createWriteStream(zipPath);
        const arc = archiver('zip', { zlib: { level: 9 } });
        output.on('close', resolve);
        arc.on('error', reject);
        arc.pipe(output);
        arc.glob('**/*', { cwd: sourceDir, ignore: ['archive.zip'] });
        arc.finalize();
    });

// ─────────────────────────────────────────────
// Get raw HTML from live page
// ─────────────────────────────────────────────

const getRawHtml = async (page) =>
    page.evaluate(() => {
        const toAbsolute = (url) => {
            try { return new URL(url, document.baseURI).href; } catch { return url; }
        };

        document.querySelectorAll('[src]').forEach((el) => {
            const v = el.getAttribute('src');
            if (v && !v.startsWith('data:')) el.setAttribute('src', toAbsolute(v));
        });
        document.querySelectorAll('[href]').forEach((el) => {
            const v = el.getAttribute('href');
            if (v && !v.startsWith('data:') && !v.startsWith('#')) el.setAttribute('href', toAbsolute(v));
        });
        document.querySelectorAll('[poster]').forEach((el) => {
            const v = el.getAttribute('poster');
            if (v && !v.startsWith('data:')) el.setAttribute('poster', toAbsolute(v));
        });
        document.querySelectorAll('[srcset]').forEach((el) => {
            const v = el.getAttribute('srcset');
            if (v) {
                el.setAttribute('srcset', v.split(',').map((part) => {
                    const p = part.trim().split(/\s+/);
                    p[0] = toAbsolute(p[0]);
                    return p.join(' ');
                }).join(', '));
            }
        });
        document.querySelectorAll('[style]').forEach((el) => {
            const v = el.getAttribute('style');
            if (v && v.includes('url(')) {
                el.setAttribute('style', v.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/gi, (m, q, raw) => {
                    if (raw.startsWith('data:')) return m;
                    return `url("${toAbsolute(raw)}")`;
                }));
            }
        });
        document.querySelectorAll('style').forEach((el) => {
            if (el.textContent && el.textContent.includes('url(')) {
                el.textContent = el.textContent.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/gi, (m, q, raw) => {
                    if (raw.startsWith('data:') || raw.startsWith('blob:')) return m;
                    return `url("${toAbsolute(raw)}")`;
                });
            }
        });

        const doctype = document.doctype
            ? `<!DOCTYPE ${document.doctype.name}>`
            : '<!DOCTYPE html>';
        return `${doctype}\n${document.documentElement.outerHTML}`;
    });

// ─────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────

let browser;

try {
    await ensureDir(outputDir);
    const assetsDir = path.join(outputDir, 'assets');
    await ensureDir(assetsDir);

    // ── Launch browser ──────────────────────────────────────────
    log('Launching Chromium…');
    browser = await puppeteer.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-web-security',
            '--disable-features=IsolateOrigins,site-per-process',
            '--allow-running-insecure-content',
            '--disable-dev-shm-usage',
        ],
    });

    // ── Desktop page ─────────────────────────────────────────────
    const page = await browser.newPage();
    await page.setCacheEnabled(false);
    await page.setViewport({ width: 1440, height: 900 });
    await page.setBypassCSP(true);
    await page.setUserAgent(
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) ' +
        'AppleWebKit/537.36 (KHTML, like Gecko) ' +
        'Chrome/122.0.0.0 Safari/537.36',
    );

    // Attach CDP resource capture BEFORE first navigation
    const { client, resourceMap } = await attachResourceCapture(page);

    // ── Navigate ──────────────────────────────────────────────────
    log(`Navigating → ${targetUrl}`);
    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await waitForSettledNetwork(page, 15_000);

    log('Scrolling to trigger lazy content…');
    await autoScroll(page);
    await waitForContent(page);

    await new Promise((r) => setTimeout(r, 2_000));
    await waitForSettledNetwork(page, 12_000);

    log(`Resources captured: ${resourceMap.size}`);

    // ── MHTML via CDP ─────────────────────────────────────────────
    log('Saving MHTML…');
    try {
        const { data: mhtmlData } = await client.send('Page.captureSnapshot', { format: 'mhtml' });
        await fs.writeFile(path.join(outputDir, 'page.mhtml'), mhtmlData, 'utf8');
        log('MHTML saved.');
    } catch (e) {
        log(`MHTML skipped: ${e.message}`);
    }

    // ── PDF ───────────────────────────────────────────────────────
    log('Exporting PDF…');
    try {
        await page.pdf({
            path: path.join(outputDir, 'page.pdf'),
            format: 'A4',
            printBackground: true,
            margin: { top: '10mm', bottom: '10mm', left: '10mm', right: '10mm' },
        });
        log('PDF saved.');
    } catch (e) {
        log(`PDF skipped: ${e.message}`);
    }

    // ── Desktop screenshots ───────────────────────────────────────
    log('Capturing desktop screenshots…');
    await page.screenshot({
        path: path.join(outputDir, 'screenshot-desktop.png'),
        fullPage: true,
        type: 'png',
    });
    await page.screenshot({
        path: path.join(outputDir, 'screenshot-viewport.png'),
        fullPage: false,
        type: 'png',
    });

    // ── Snapshot data ─────────────────────────────────────────────
    const rawHtml    = await getRawHtml(page);
    const pageTitle  = await page.title();
    const finalUrl   = page.url();
    const userAgent  = await page.evaluate(() => navigator.userAgent);
    const pageMetrics = await page.metrics();

    // ── Mobile screenshot (separate page to avoid reload) ─────────
    log('Capturing mobile screenshot…');
    const mobilePage = await browser.newPage();
    await mobilePage.setCacheEnabled(false);
    await mobilePage.setBypassCSP(true);
    await mobilePage.emulate({
        viewport: { width: 390, height: 844, isMobile: true, deviceScaleFactor: 3 },
        userAgent:
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) ' +
            'AppleWebKit/605.1.15 (KHTML, like Gecko) ' +
            'Version/17.0 Mobile/15E148 Safari/604.1',
    });
    await mobilePage.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await waitForSettledNetwork(mobilePage, 10_000);
    await autoScroll(mobilePage);
    await waitForContent(mobilePage);
    await new Promise((r) => setTimeout(r, 1_000));
    await mobilePage.screenshot({
        path: path.join(outputDir, 'screenshot-mobile.png'),
        fullPage: true,
        type: 'png',
    });
    await mobilePage.close();

    // ── Linked HTML (assets on disk, URLs rewritten) ──────────────
    log('Building linked HTML with local assets…');
    const linkedHtml = await buildLinkedSnapshot(rawHtml, resourceMap, assetsDir, 'assets');
    await fs.writeFile(path.join(outputDir, 'page.html'), linkedHtml, 'utf8');
    log(`Saved assets to ${assetsDir}`);

    // ── Single-file inline HTML ───────────────────────────────────
    log('Building single-file inline HTML…');
    const inlineHtml = buildInlineSnapshot(rawHtml, resourceMap);
    await fs.writeFile(path.join(outputDir, 'page.inline.html'), inlineHtml, 'utf8');
    log('Inline HTML saved.');

    // ── HAR network log ───────────────────────────────────────────
    const harEntries = [...resourceMap.entries()].map(([url, { mimeType, body, status }]) => ({
        url,
        status,
        mimeType,
        bytes: body.length,
    }));
    await writeJson(path.join(outputDir, 'network.har'), {
        log: {
            version: '1.2',
            creator: { name: 'capture-page', version: '2.0.0' },
            entries: harEntries,
        },
    });

    // ── Metadata ──────────────────────────────────────────────────
    const byType = [...resourceMap.values()].reduce((acc, { mimeType }) => {
        const key = (mimeType || 'unknown').split('/')[0];
        acc[key] = (acc[key] || 0) + 1;
        return acc;
    }, {});
    const totalBytes = [...resourceMap.values()].reduce((s, { body }) => s + body.length, 0);

    const metadata = {
        url: targetUrl,
        finalUrl,
        title: pageTitle,
        capturedAt: new Date().toISOString(),
        userAgent,
        viewport: { width: 1440, height: 900 },
        resources: {
            total: resourceMap.size,
            byType,
            totalBytes,
            totalKB: Math.round(totalBytes / 1024),
        },
        metrics: pageMetrics,
        files: {
            html:               'page.html',
            inlineHtml:         'page.inline.html',
            mhtml:              'page.mhtml',
            pdf:                'page.pdf',
            screenshotDesktop:  'screenshot-desktop.png',
            screenshotViewport: 'screenshot-viewport.png',
            screenshotMobile:   'screenshot-mobile.png',
            har:                'network.har',
            archive:            'archive.zip',
        },
    };
    await writeJson(path.join(outputDir, 'metadata.json'), metadata);

    // ── ZIP ───────────────────────────────────────────────────────
    log('Creating ZIP archive…');
    const zipPath = path.join(outputDir, 'archive.zip');
    await createZip(outputDir, zipPath);

    log(`✓ Done — ${resourceMap.size} resources, ${Math.round(totalBytes / 1024)} KB total`);

    // ── Result to stdout ──────────────────────────────────────────
    process.stdout.write(
        JSON.stringify({
            success: true,
            outputDir,
            title: pageTitle,
            resourceCount: resourceMap.size,
            totalKB: Math.round(totalBytes / 1024),
            files: metadata.files,
        }, null, 2),
    );

} catch (error) {
    process.stderr.write(
        JSON.stringify({
            success: false,
            message: error instanceof Error ? error.message : String(error),
            stack:   error instanceof Error ? error.stack   : undefined,
            targetUrl,
            outputDir,
        }, null, 2) + '\n',
    );
    process.exit(1);
} finally {
    if (browser) await browser.close();
}
