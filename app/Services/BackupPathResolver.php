<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BackupPathResolver
{
    public function resolveBackupAbsolutePath(string $storedPath): string
    {
        $normalizedPath = str_replace('\\', '/', trim($storedPath));
        $privateRoot = str_replace('\\', '/', storage_path('app/private'));

        if (str_starts_with($normalizedPath, $privateRoot.'/')) {
            return str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        }

        if (preg_match('/^[A-Za-z]:\//', $normalizedPath) === 1) {
            return str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        }

        $relative = ltrim($normalizedPath, '/');

        return storage_path('app/private/'.$relative);
    }

    public function resolveGeneratedThumbnailPath(int $sourceId): ?string
    {
        $captureRoot = storage_path("app/private/capturas/fuente_{$sourceId}");
        $preferredCandidates = [
            $captureRoot.DIRECTORY_SEPARATOR.'page.png',
            $captureRoot.DIRECTORY_SEPARATOR.'collections'.DIRECTORY_SEPARATOR."fuente_{$sourceId}".DIRECTORY_SEPARATOR.'page.png',
        ];

        foreach ($preferredCandidates as $candidatePath) {
            if (File::exists($candidatePath)) {
                return $candidatePath;
            }
        }

        if (!File::isDirectory($captureRoot)) {
            return null;
        }

        return collect(File::allFiles($captureRoot))
            ->filter(function (\SplFileInfo $file): bool {
                $path = str_replace('\\', '/', strtolower($file->getPathname()));

                if (!preg_match('/\.(png|jpe?g|webp)$/i', $path)) {
                    return false;
                }

                if (str_contains($path, '/profile/')) {
                    return false;
                }

                return str_contains($path, '/pages/') || str_contains($path, '/screenshots/');
            })
            ->sortByDesc(fn (\SplFileInfo $file): int => $file->getMTime())
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->first();
    }

    public function resolveGeneratedWaczPath(int $sourceId): ?string
    {
        $captureRoot = storage_path("app/private/capturas/fuente_{$sourceId}");

        if (!File::isDirectory($captureRoot)) {
            return null;
        }

        $preferredCandidates = [
            $captureRoot.DIRECTORY_SEPARATOR."fuente_{$sourceId}.wacz",
            $captureRoot.DIRECTORY_SEPARATOR.'collections'.DIRECTORY_SEPARATOR."fuente_{$sourceId}".DIRECTORY_SEPARATOR."fuente_{$sourceId}.wacz",
        ];

        foreach ($preferredCandidates as $candidatePath) {
            if (File::exists($candidatePath)) {
                return $candidatePath;
            }
        }

        return collect(File::allFiles($captureRoot))
            ->filter(function (\SplFileInfo $file): bool {
                $path = str_replace('\\', '/', strtolower($file->getPathname()));

                return Str::endsWith($path, '.wacz') && !str_contains($path, '/profile/');
            })
            ->sortByDesc(fn (\SplFileInfo $file): int => $file->getMTime())
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->first();
    }

    public function resolveStoredBackupPath(string $absolutePath): string
    {
        $privateRoot = str_replace('\\', '/', storage_path('app/private'));
        $normalizedPath = str_replace('\\', '/', $absolutePath);

        return ltrim((string) Str::after($normalizedPath, $privateRoot), '/');
    }
}
