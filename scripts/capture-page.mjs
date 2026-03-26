/* global process */

import fs from 'node:fs/promises';
import { appendFile } from 'node:fs/promises';
import { createWriteStream } from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer';
import scribe from 'scribe.js-ocr';

const [, , targetUrl, outputDir] = process.argv;

const debugLog = async (message) => {
    try {
        const time = new Date().toISOString();
        const logMsg = `[DEBUG ${time}] ${message}\n`;
        // Intentar escribir en el archivo debug.log localmente
        await appendFile(path.join(process.cwd(), 'debug-ocr.log'), logMsg, 'utf8');
        // También tratar de imprimirlo a std error por si acaso
        console.error(logMsg.trim());
    } catch(e) {}
};

if (!targetUrl || !outputDir) {
    debugLog('Usage: node scripts/capture-page.mjs <url> <outputDir>');
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

const getPngDimensions = async (filePath) => {
    const fileBuffer = await fs.readFile(filePath);

    // PNG width/height are stored as big-endian uint32 values in IHDR.
    const pngSignature = '89504e470d0a1a0a';
    const hasValidSignature = fileBuffer.subarray(0, 8).toString('hex') === pngSignature;

    if (!hasValidSignature) {
        throw new Error(`Invalid PNG signature for file: ${filePath}`);
    }

    const width = fileBuffer.readUInt32BE(16);
    const height = fileBuffer.readUInt32BE(20);

    return { width, height };
};

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

const processOcrAndPdf = async (pngPath, pdfPath, ocrPath) => {
    await debugLog(`Initializing scribe.js-ocr...`);
    await scribe.init();
    
    // Configurar Scribe para que el texto escaneado en el PDF sea invisible (capa de texto buscable)
    scribe.opt.displayMode = 'invis';
    
    const absolutePngPath = path.resolve(pngPath);
    await debugLog(`Importing file to scribe: ${absolutePngPath}`);
    await scribe.importFiles([absolutePngPath]);

    await debugLog(`Running OCR (Language: spa+eng)...`);
    await scribe.recognize('spa+eng');

    await debugLog(`Extracting text and saving to: ${ocrPath}`);
    const ocrText = await scribe.exportData('txt');
    await writeText(ocrPath, typeof ocrText === 'string' ? ocrText : ocrText.toString('utf8'));

    await debugLog(`Exporting searchable PDF natively via scribe to: ${pdfPath}`);
    const pdfData = await scribe.exportData('pdf');
    await fs.writeFile(pdfPath, Buffer.from(pdfData));

    await debugLog(`Cleaning up scribe resources...`);
    await scribe.terminate();
};

const autoScroll = async (page) => {
    await debugLog(`Executing autoScroll script within page...`);
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
    await debugLog(`autoScroll finished.`);
};

let browser;

try {
    await debugLog(`Starting capture for URL: ${targetUrl}`);
    await debugLog(`Ensuring output directory exists: ${outputDir}`);
    await ensureDir(outputDir);

    await debugLog(`Launching Puppeteer browser...`);
    browser = await puppeteer.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    await debugLog(`Creating new page...`);
    const page = await browser.newPage();
    
    // Capturar logs de la consola web
    page.on('console', async (msg) => {
        await debugLog(`[WEB CONSOLE] ${msg.type().toUpperCase()}: ${msg.text()}`);
    });
    // Capturar errores de JS en la página
    page.on('pageerror', async (error) => {
        await debugLog(`[WEB ERROR] ${error.message}`);
    });
    // Capturar peticiones fallidas de red
    page.on('requestfailed', async (request) => {
        await debugLog(`[WEB REQUEST FAILED] ${request.url()} - ${request.failure()?.errorText || 'Unknown error'}`);
    });

    await page.setViewport({ width: 1440, height: 900 });
    await page.setBypassCSP(true);
    
    await debugLog(`Navigating to URL...`);
    await page.goto(targetUrl, { waitUntil: 'networkidle0', timeout: 90000 });
    
    await debugLog(`Scrolling page to trigger lazy loading...`);
    await autoScroll(page);
    await new Promise((resolve) => setTimeout(resolve, 1200));

    const screenshotPath = path.join(outputDir, 'page.png');
    const pdfPath = path.join(outputDir, 'page.pdf');
    const ocrPath = path.join(outputDir, 'ocr.txt');
    const metadataPath = path.join(outputDir, 'metadata.json');

    await debugLog(`Taking screenshot to: ${screenshotPath}`);
    await page.screenshot({
        path: screenshotPath,
        fullPage: true,
        type: 'png',
    });

    await processOcrAndPdf(screenshotPath, pdfPath, ocrPath);

    await debugLog(`Gathering metadata...`);
    const metadata = {
        url: targetUrl,
        capturedAt: new Date().toISOString(),
        screenshotPath,
        pdfPath,
        ocrPath,
        title: await page.title(),
        finalUrl: page.url(),
        userAgent: await page.evaluate(() => navigator.userAgent),
    };

    await debugLog(`Saving metadata to: ${metadataPath}`);
    await writeJson(metadataPath, metadata);

    await debugLog(`Process completed successfully, outputting JSON...`);
    process.stdout.write(
        JSON.stringify({
            screenshotPath,
            pdfPath,
            ocrPath,
            metadataPath,
        }),
    );
} catch (error) {
    if (error instanceof Error) {
        await debugLog(`Unhandled exception occurred: ${error.stack}`);
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
