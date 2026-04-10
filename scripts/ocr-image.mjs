import { existsSync } from 'node:fs';
import { createWorker } from 'tesseract.js';

const imagePath = process.argv[2];
const languages = process.argv[3] || 'spa+eng';

if (!imagePath) {
    console.error('Missing image path argument.');
    process.exit(2);
}

if (!existsSync(imagePath)) {
    console.error(`Image file not found: ${imagePath}`);
    process.exit(3);
}

let worker;

try {
    worker = await createWorker(languages);
    const { data } = await worker.recognize(imagePath);
    const text = typeof data?.text === 'string' ? data.text : '';

    process.stdout.write(text.replace(/[ \t]+\n/g, '\n').trim());
} catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.error(message);
    process.exit(1);
} finally {
    if (worker) {
        await worker.terminate();
    }
}
