import fs from 'node:fs/promises';
import path from 'node:path';
import puppeteer from 'puppeteer';

const [, , targetUrl, outputDir] = process.argv;

if (!targetUrl || !outputDir) {
    console.error('Usage: node scripts/capture-page.mjs <url> <outputDir>');
    process.exit(1);
}

const ensureDir = async (dir) => {
    await fs.mkdir(dir, { recursive: true });
};

const writeJson = async (filePath, value) => {
    await fs.writeFile(filePath, JSON.stringify(value, null, 2), 'utf8');
};

const autoScroll = async (page) => {
    await page.evaluate(async () => {
        await new Promise((resolve) => {
            let totalHeight = 0;
            const distance = 500;
            const timer = setInterval(() => {
                window.scrollBy(0, distance);
                totalHeight += distance;

                if (totalHeight >= document.body.scrollHeight) {
                    clearInterval(timer);
                    resolve();
                }
            }, 120);
        });

        window.scrollTo(0, 0);
    });
};

const preparePortableHtml = async (page, baseUrl) => {
    return page.evaluate((targetBaseUrl) => {
        const toAbsolute = (rawValue) => {
            if (!rawValue || rawValue.startsWith('data:') || rawValue.startsWith('blob:') || rawValue.startsWith('#')) {
                return rawValue;
            }

            try {
                return new URL(rawValue, window.location.href).href;
            } catch {
                return rawValue;
            }
        };

        const absolutizeSrcset = (srcsetValue) => {
            if (!srcsetValue) {
                return srcsetValue;
            }

            return srcsetValue
                .split(',')
                .map((candidate) => {
                    const trimmed = candidate.trim();
                    if (!trimmed) {
                        return trimmed;
                    }

                    const parts = trimmed.split(/\s+/);
                    const urlPart = parts.shift();
                    const descriptor = parts.join(' ');
                    const absolute = toAbsolute(urlPart ?? '');

                    return descriptor ? `${absolute} ${descriptor}` : absolute;
                })
                .join(', ');
        };

        const head = document.head;
        if (head) {
            let base = head.querySelector('base[data-hemeroteca-backup="1"]');
            if (!base) {
                base = document.createElement('base');
                base.setAttribute('data-hemeroteca-backup', '1');
                head.prepend(base);
            }
            base.setAttribute('href', targetBaseUrl);

            head.querySelectorAll('meta[http-equiv]').forEach((meta) => {
                const httpEquiv = (meta.getAttribute('http-equiv') || '').toLowerCase();
                if (httpEquiv === 'content-security-policy' || httpEquiv === 'x-content-security-policy') {
                    meta.remove();
                }
            });
        }

        document.querySelectorAll('[src]').forEach((element) => {
            element.setAttribute('src', toAbsolute(element.getAttribute('src') || ''));
        });

        document.querySelectorAll('[href]').forEach((element) => {
            element.setAttribute('href', toAbsolute(element.getAttribute('href') || ''));
        });

        document.querySelectorAll('img[srcset], source[srcset]').forEach((element) => {
            element.setAttribute('srcset', absolutizeSrcset(element.getAttribute('srcset') || ''));
        });

        document.querySelectorAll('[poster]').forEach((element) => {
            element.setAttribute('poster', toAbsolute(element.getAttribute('poster') || ''));
        });

        document.querySelectorAll('[style]').forEach((element) => {
            const styleText = element.getAttribute('style') || '';
            const rewritten = styleText.replace(/url\(([^)]+)\)/gi, (_, urlToken) => {
                const cleaned = urlToken.trim().replace(/^['"]|['"]$/g, '');
                const absolute = toAbsolute(cleaned);
                return `url("${absolute}")`;
            });
            element.setAttribute('style', rewritten);
        });

        const doctype = document.doctype
            ? `<!DOCTYPE ${document.doctype.name}>`
            : '<!DOCTYPE html>';

        return `${doctype}\n${document.documentElement.outerHTML}`;
    }, baseUrl);
};

let browser;

try {
    await ensureDir(outputDir);

    browser = await puppeteer.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    await page.setBypassCSP(true);
    await page.goto(targetUrl, { waitUntil: 'networkidle0', timeout: 90000 });
    await autoScroll(page);
    await new Promise((resolve) => setTimeout(resolve, 1200));

    const htmlPath = path.join(outputDir, 'page.html');
    const screenshotPath = path.join(outputDir, 'page.png');
    const metadataPath = path.join(outputDir, 'metadata.json');

    const html = await preparePortableHtml(page, targetUrl);
    await fs.writeFile(htmlPath, html, 'utf8');

    await page.screenshot({
        path: screenshotPath,
        fullPage: true,
        type: 'png',
    });

    const metadata = {
        url: targetUrl,
        capturedAt: new Date().toISOString(),
        htmlPath,
        screenshotPath,
        title: await page.title(),
        finalUrl: page.url(),
        userAgent: await page.evaluate(() => navigator.userAgent),
    };

    await writeJson(metadataPath, metadata);

    process.stdout.write(
        JSON.stringify({
            htmlPath,
            screenshotPath,
            metadataPath,
        }),
    );
} catch (error) {
    if (error instanceof Error) {
        console.error(
            JSON.stringify(
                {
                    message: error.message,
                    name: error.name,
                    stack: error.stack,
                    targetUrl,
                    outputDir,
                },
                null,
                2,
            ),
        );
    } else {
        console.error(
            JSON.stringify(
                {
                    message: String(error),
                    targetUrl,
                    outputDir,
                },
                null,
                2,
            ),
        );
    }
    process.exit(1);
} finally {
    if (browser) {
        await browser.close();
    }
}
