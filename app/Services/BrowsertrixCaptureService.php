<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class BrowsertrixCaptureService
{
    public function __construct(
        private readonly BackupPathResolver $pathResolver,
    ) {
    }

    public function capture(int $sourceId, string $url): string
    {
        $relativeCaptureDir = "capturas/fuente_{$sourceId}";
        $absoluteCaptureDir = storage_path("app/private/{$relativeCaptureDir}");
        $tmpDir = storage_path('app/tmp');

        File::ensureDirectoryExists($absoluteCaptureDir);
        File::ensureDirectoryExists($tmpDir);

        $dockerBinary = env('DOCKER_BINARY', 'docker');
        $browsertrixImage = env('BROWSERTRIX_IMAGE', 'webrecorder/browsertrix-crawler');
        $captureTimeout = (int) env('CAPTURE_PROCESS_TIMEOUT', 420);
        $collectionName = "fuente_{$sourceId}";
        $crawlerTimeout = $this->isFacebookDomain($url) ? 60 : 30;
        $crawlerLimit = $this->isFacebookDomain($url) ? 2 : 1;
        $crawlerBehaviorTimeout = $this->isFacebookDomain($url) ? 40 : 10;
        $captureSizeLimitBytes = (int) env('CAPTURE_SIZE_LIMIT_BYTES', 209715200);

        $process = new Process([
            $dockerBinary,
            'run',
            '--rm',
            '-v',
            "{$absoluteCaptureDir}:/crawls",
            $browsertrixImage,
            'crawl',
            '--url',
            $url,
            '--extraChromeArgs',
            '--disable-blink-features=AutomationControlled',
            '--userAgent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            '--generateWACZ',
            '--headless',
            '--collection',
            $collectionName,
            '--timeout',
            (string) $crawlerTimeout,
            '--limit',
            (string) $crawlerLimit,
            '--text',
            'to-pages',
            '--sizeLimit',
            (string) $captureSizeLimitBytes,
            '--behaviorTimeout',
            (string) $crawlerBehaviorTimeout,
        ], base_path(), [
            'SYSTEMROOT' => env('SYSTEMROOT', 'C:\\Windows'),
            'WINDIR' => env('WINDIR', 'C:\\Windows'),
            'TEMP' => env('TEMP', $tmpDir),
            'TMP' => env('TMP', $tmpDir),
            'PATH' => env('PATH', getenv('PATH') ?: ''),
        ]);

        $process->setTimeout($captureTimeout);

        Log::info('Iniciando captura de respaldo con Browsertrix.', [
            'source_id' => $sourceId,
            'url' => $url,
            'docker_binary' => $dockerBinary,
            'browsertrix_image' => $browsertrixImage,
            'collection' => $collectionName,
            'timeout_seconds' => $captureTimeout,
            'crawler_timeout_seconds' => $crawlerTimeout,
            'crawler_limit' => $crawlerLimit,
            'crawler_behavior_timeout_seconds' => $crawlerBehaviorTimeout,
            'capture_size_limit_bytes' => $captureSizeLimitBytes,
            'capture_dir' => $absoluteCaptureDir,
            'tmp_dir' => $tmpDir,
            'command' => $process->getCommandLine(),
        ]);

        $process->mustRun();

        $thumbnailCaptured = false;
        try {
            $thumbnailCaptured = $this->captureThumbnailWithPlaywright($sourceId, $url, $absoluteCaptureDir, $tmpDir);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo generar la miniatura con Playwright.', [
                'source_id' => $sourceId,
                'url' => $url,
                'thumbnail_path' => $absoluteCaptureDir.DIRECTORY_SEPARATOR.'page.png',
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        $waczPath = $this->pathResolver->resolveGeneratedWaczPath($sourceId);
        if (!is_string($waczPath)) {
            throw new \RuntimeException('Browsertrix finalizo, pero no se encontro ningun archivo .wacz.');
        }

        $storedBackupPath = $this->pathResolver->resolveStoredBackupPath($waczPath);
        if ($storedBackupPath === '') {
            throw new \RuntimeException('No se pudo resolver la ruta relativa del archivo .wacz generado.');
        }

        Log::info('Captura de respaldo finalizada correctamente.', [
            'source_id' => $sourceId,
            'url' => $url,
            'stored_backup_path' => $storedBackupPath,
            'playwright_thumbnail_captured' => $thumbnailCaptured,
            'wacz_exists' => File::exists($waczPath),
            'png_exists' => File::exists("{$absoluteCaptureDir}/page.png"),
            'metadata_exists' => File::exists("{$absoluteCaptureDir}/metadata.json"),
            ...$this->captureDirectorySnapshot($absoluteCaptureDir),
            'stdout' => $this->truncateLogValue($process->getOutput()),
            'stderr' => $this->truncateLogValue($process->getErrorOutput()),
        ]);

        return $storedBackupPath;
    }

    private function captureThumbnailWithPlaywright(
        int $sourceId,
        string $url,
        string $absoluteCaptureDir,
        string $tmpDir,
    ): bool {
        $thumbnailPath = $absoluteCaptureDir.DIRECTORY_SEPARATOR.'page.png';
        File::ensureDirectoryExists(dirname($thumbnailPath));

        $nodeBinary = env('NODE_BINARY', 'node');
        $thumbnailTimeout = (int) env('PLAYWRIGHT_SCREENSHOT_TIMEOUT', 90);
        $scriptPath = $tmpDir.DIRECTORY_SEPARATOR."playwright_screenshot_fuente_{$sourceId}.mjs";

        File::put($scriptPath, <<<'JS'
import { chromium } from 'playwright';

const [url, outputPath] = process.argv.slice(2);

if (!url || !outputPath) {
    throw new Error('Missing url or outputPath argument.');
}

const browser = await chromium.launch({ headless: true });

try {
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: outputPath, fullPage: true, type: 'png' });
} finally {
    await browser.close();
}
JS
        );

        $process = new Process([
            $nodeBinary,
            $scriptPath,
            $url,
            $thumbnailPath,
        ], base_path(), [
            'SYSTEMROOT' => env('SYSTEMROOT', 'C:\\Windows'),
            'WINDIR' => env('WINDIR', 'C:\\Windows'),
            'TEMP' => env('TEMP', $tmpDir),
            'TMP' => env('TMP', $tmpDir),
            'PATH' => env('PATH', getenv('PATH') ?: ''),
        ]);

        $process->setTimeout($thumbnailTimeout);
        $process->mustRun();

        return File::exists($thumbnailPath);
    }

    private function truncateLogValue(string $value, int $limit = 3000): string
    {
        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit).'... [truncated]'
            : $value;
    }

    private function isFacebookDomain(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = strtolower($host);

        if ($host === '') {
            return false;
        }

        return $host === 'facebook.com'
            || str_ends_with($host, '.facebook.com')
            || $host === 'fb.com'
            || str_ends_with($host, '.fb.com');
    }

    private function captureDirectorySnapshot(string $absoluteCaptureDir): array
    {
        if (!File::isDirectory($absoluteCaptureDir)) {
            return [
                'capture_dir_exists' => false,
                'capture_files' => [],
            ];
        }

        $normalizedRoot = str_replace('\\', '/', $absoluteCaptureDir);
        $files = collect(File::allFiles($absoluteCaptureDir))
            ->map(function (\SplFileInfo $file) use ($normalizedRoot): array {
                $filePath = str_replace('\\', '/', $file->getPathname());
                $relativePath = ltrim((string) Str::after($filePath, $normalizedRoot), '/');

                return [
                    'path' => $relativePath !== '' ? $relativePath : basename($filePath),
                    'size' => $file->getSize(),
                ];
            })
            ->values()
            ->all();

        return [
            'capture_dir_exists' => true,
            'capture_files' => $files,
        ];
    }
}
