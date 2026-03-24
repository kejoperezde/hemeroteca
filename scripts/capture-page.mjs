/* global process */

import fs from 'node:fs/promises';
import path from 'node:path';
import puppeteer from 'puppeteer';
import { createWorker } from 'tesseract.js';

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

const writeText = async (filePath, value) => {
    await fs.writeFile(filePath, value, 'utf8');
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

const extractTextFromImage = async (imagePath) => {
    const worker = await createWorker('spa+eng');

    try {
        const {
            data: { text },
        } = await worker.recognize(imagePath);

        return (text ?? '').trim();
    } finally {
        await worker.terminate();
    }
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

    const screenshotPath = path.join(outputDir, 'page.png');
    const ocrPath = path.join(outputDir, 'ocr.txt');
    const metadataPath = path.join(outputDir, 'metadata.json');

    await page.screenshot({
        path: screenshotPath,
        fullPage: true,
        type: 'png',
    });

    const ocrText = await extractTextFromImage(screenshotPath);
    await writeText(ocrPath, ocrText);

    const metadata = {
        url: targetUrl,
        capturedAt: new Date().toISOString(),
        screenshotPath,
        ocrPath,
        title: await page.title(),
        finalUrl: page.url(),
        ocrCharacters: ocrText.length,
        userAgent: await page.evaluate(() => navigator.userAgent),
    };

    await writeJson(metadataPath, metadata);

    process.stdout.write(
        JSON.stringify({
            screenshotPath,
            ocrPath,
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
